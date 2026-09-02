<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Arn;
use Codinglabs\Yolo\Aws;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Audit\Audit;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

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
            // ClusterNotFoundException (cold account) must read as not-found too
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
     * One describeServices call, so probing a preference order costs no extra
     * round trips.
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
     * A missing service yields [] rather than throwing — the caller decides what
     * "no tasks here" means.
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
     * The authoritative liveness signal, shared by the audit's ownership
     * attribution and the service lifecycle's claim gating.
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
     * An unknown cluster yields [] rather than throwing — "no running tasks"
     * reads as "this app isn't live".
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
     * ELB→ECS consistency lags the forward-rule step by a few seconds, so a first
     * CreateService can report the target group unattached. Matched narrowly on
     * that message so any other InvalidParameterException still fails fast.
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
     * "No active services" is not the precondition: a force-deleted service
     * drops off ListServices the instant it enters DRAINING, well before its tasks
     * finish stopping, so retry the delete against AWS's own check instead.
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
