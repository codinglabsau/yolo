<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Arn;
use Codinglabs\Yolo\Aws;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Audit\Audit;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Thin per-service wrapper around the ECS SDK client. Each method takes the
 * identifier it needs (no manifest reads, no keyedResourceName) and returns
 * the parsed AWS response shape — or throws ResourceDoesNotExistException
 * when the resource isn't found.
 */
class Ecs
{
    public static function cluster(string $name): array
    {
        $clusters = Aws::ecs()->describeClusters([
            'clusters' => [$name],
        ])['clusters'];

        foreach ($clusters as $cluster) {
            if ($cluster['status'] !== 'INACTIVE') {
                return $cluster;
            }
        }

        throw new ResourceDoesNotExistException("Could not find ECS cluster $name");
    }

    public static function service(string $cluster, string $name): array
    {
        try {
            $services = Aws::ecs()->describeServices([
                'cluster' => $cluster,
                'services' => [$name],
            ])['services'];
        } catch (AwsException) {
            // Surfaces ClusterNotFoundException (cold account), ServiceNotFoundException,
            // and any other SDK error as the project's standard not-found signal so the
            // calling resource's catch can decide dry-run vs create.
            throw new ResourceDoesNotExistException("Could not find ECS service $name");
        }

        foreach ($services as $service) {
            if ($service['status'] !== 'INACTIVE') {
                return $service;
            }
        }

        throw new ResourceDoesNotExistException("Could not find ECS service $name");
    }

    /**
     * The first live service among $names (in the given order) — one
     * describeServices call, so probing a preference order costs no extra round
     * trips. Missing names land in the response's `failures` list and INACTIVE
     * ones are skipped, exactly as in {@see service()}. Throws when none exist.
     *
     * @param  array<int, string>  $names
     */
    public static function firstService(string $cluster, array $names): array
    {
        try {
            $services = Aws::ecs()->describeServices([
                'cluster' => $cluster,
                'services' => $names,
            ])['services'];
        } catch (AwsException) {
            throw new ResourceDoesNotExistException(sprintf('Could not find any ECS service of: %s', implode(', ', $names)));
        }

        $live = collect($services)
            ->filter(fn (array $service): bool => $service['status'] !== 'INACTIVE')
            ->keyBy('serviceName');

        foreach ($names as $name) {
            if ($live->has($name)) {
                return $live->get($name);
            }
        }

        throw new ResourceDoesNotExistException(sprintf('Could not find any ECS service of: %s', implode(', ', $names)));
    }

    /**
     * Running task ARNs for a service. A missing service (e.g. a group that
     * isn't its own service yet) yields an empty list rather than throwing —
     * the caller decides what "no tasks here" means.
     *
     * @return array<int, string>
     */
    public static function runningTasks(string $cluster, string $service): array
    {
        try {
            return Aws::ecs()->listTasks([
                'cluster' => $cluster,
                'serviceName' => $service,
                'desiredStatus' => 'RUNNING',
            ])['taskArns'];
        } catch (AwsException) {
            return [];
        }
    }

    /**
     * Every ECS cluster ARN in the account, across all pages.
     *
     * @return array<int, string>
     */
    public static function clusterArns(): array
    {
        $arns = [];
        $token = null;

        do {
            $result = Aws::ecs()->listClusters(array_filter(['nextToken' => $token]));
            $arns = [...$arns, ...($result['clusterArns'] ?? [])];
            $token = $result['nextToken'] ?? null;
        } while ($token);

        return $arns;
    }

    /**
     * Apps with at least one running Fargate task in this environment — the
     * authoritative "what's actually deployed" liveness signal, shared by the
     * audit's ownership attribution and the service lifecycle's claim gating.
     * Only clusters in the environment's yolo-{env}- namespace are probed, so
     * unrelated clusters are never listed.
     *
     * @return array<int, string>
     */
    public static function liveApps(string $environment): array
    {
        $prefix = "yolo-$environment-";

        $liveClusters = collect(static::clusterArns())
            ->filter(fn (string $arn): bool => str_starts_with(Arn::parse($arn)->resourceId ?? '', $prefix))
            ->filter(fn (string $arn): bool => static::clusterRunningTasks($arn) !== [])
            ->all();

        return Audit::appsFromClusters($liveClusters, $environment);
    }

    /**
     * Running task ARNs across an entire cluster (any service). An unknown
     * cluster yields an empty list rather than throwing — the caller treats
     * "no running tasks" as "this app isn't live".
     *
     * @return array<int, string>
     */
    public static function clusterRunningTasks(string $cluster): array
    {
        try {
            return Aws::ecs()->listTasks([
                'cluster' => $cluster,
                'desiredStatus' => 'RUNNING',
            ])['taskArns'];
        } catch (AwsException) {
            return [];
        }
    }

    public static function taskDefinition(string $family): array
    {
        try {
            return Aws::ecs()->describeTaskDefinition([
                'taskDefinition' => $family,
            ])['taskDefinition'];
        } catch (AwsException) {
            throw new ResourceDoesNotExistException("Could not find ECS task definition $family");
        }
    }

    /**
     * Create a service, retrying the brief window after its target group is wired
     * onto the ALB listener where ECS still reports the group unattached. The
     * forward-rule step associates the target group only a few steps before this
     * runs; ELB→ECS consistency lags a few seconds, so a first CreateService can
     * fail with "targetGroup ... does not have an associated load balancer" even
     * though the rule is in place. Matched narrowly on that message so every other
     * InvalidParameterException (a genuine misconfiguration) still fails fast.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function createServiceWhenTargetGroupAttached(array $payload, int $maxAttempts = 6, int $sleepSeconds = 5): void
    {
        $attempt = 0;

        while (true) {
            try {
                Aws::ecs()->createService($payload);

                return;
            } catch (AwsException $exception) {
                $attempt++;

                $targetGroupNotYetAttached = $exception->getAwsErrorCode() === 'InvalidParameterException'
                    && str_contains((string) $exception->getAwsErrorMessage(), 'does not have an associated load balancer');

                if ($attempt >= $maxAttempts || ! $targetGroupNotYetAttached) {
                    throw $exception;
                }

                sleep($sleepSeconds);
            }
        }
    }

    /**
     * Delete a cluster, retrying while it still reports active tasks. AWS refuses
     * DeleteCluster while any task is non-STOPPED, and "no active services" is not
     * the same precondition: a force-deleted service drops off ListServices the
     * instant it enters DRAINING — well before its tasks finish stopping over the
     * graceful-drain window — so a cluster delete can race tasks that are still
     * STOPPING even when there's no service left to wait on. Rather than guess at
     * task-status semantics, we retry the delete itself against AWS's own
     * precondition check until the drain completes. Any other error (or exhausting
     * the attempts) propagates unchanged.
     */
    public static function deleteClusterWhenDrained(string $cluster, int $maxAttempts = 40, int $sleepSeconds = 15): void
    {
        $attempt = 0;

        while (true) {
            try {
                Aws::ecs()->deleteCluster(['cluster' => $cluster]);

                return;
            } catch (AwsException $exception) {
                $attempt++;

                if ($attempt >= $maxAttempts || $exception->getAwsErrorCode() !== 'ClusterContainsTasksException') {
                    throw $exception;
                }

                sleep($sleepSeconds);
            }
        }
    }
}
