<?php

namespace Codinglabs\Yolo\Resources\Ecs;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Aws\Ecs\Exception\EcsException;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\Ec2\PublicSubnet;
use Codinglabs\Yolo\Resources\ElbV2\TargetGroup;
use Codinglabs\Yolo\Resources\Ec2\EcsTaskSecurityGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * One app's ECS service for a given workload group. Each group (web / queue /
 * scheduler) gets its own service + task-definition family so they scale
 * independently. The group defaults to web, so every bare `new EcsService()`
 * keeps meaning the web service it always did.
 *
 * Topology follows the group:
 *  - web attaches to the ALB (target group, health-check grace, container port);
 *    queue and scheduler are headless workers.
 *  - the scheduler is a pinned singleton, deployed stop-then-start so a rollout
 *    never briefly runs two crons; web and queue roll the normal way.
 *  - desired count is create-only and owned by ops/autoscaling afterwards — sync
 *    never clobbers it. The queue starts at its autoscaling floor (0 when it
 *    scales to zero); web and scheduler start at one task.
 */
class EcsService implements Deletable, Resource
{
    use ResolvesTags;

    public function __construct(protected ServerGroup $group = ServerGroup::WEB) {}

    public function group(): ServerGroup
    {
        return $this->group;
    }

    public function name(): string
    {
        return $this->keyedName($this->group);
    }

    public function scope(): Scope
    {
        return Scope::App;
    }

    public function exists(): bool
    {
        try {
            Ecs::service((new EcsCluster())->name(), $this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return Ecs::service((new EcsCluster())->name(), $this->name())['serviceArn'];
    }

    public function create(): void
    {
        // Routed through the retry helper for the web service's case: its target
        // group is attached to the ALB listener only a few steps earlier, and
        // ELB→ECS consistency can lag that write by a few seconds. Headless / queue
        // / scheduler services carry no target group, so the helper never retries
        // them — it's a plain createService for those.
        Ecs::createServiceWhenTargetGroupAttached($this->createPayload());
    }

    /**
     * Force-delete the service: `force` drains running tasks (desired → 0) and
     * removes the service in one call, so a live service tears down without a
     * separate scale-down step. A service already gone (or mid-deletion) is the
     * goal state, so the not-found / not-active codes are swallowed.
     */
    public function delete(): void
    {
        try {
            Aws::ecs()->deleteService([
                'cluster' => (new EcsCluster())->name(),
                'service' => $this->name(),
                'force' => true,
            ]);
        } catch (EcsException $e) {
            if (in_array($e->getAwsErrorCode(), ['ServiceNotFoundException', 'ServiceNotActiveException', 'ClusterNotFoundException'], true)) {
                return;
            }

            throw $e;
        }
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseEcsTags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Exec-command and (web only) grace-period drift are reconciled by
     * updateService. Desired count is NOT reconciled — capacity is set once at
     * create then owned by ops (the console, `yolo scale`, or autoscaling), so a
     * deploy/sync never resets it out from under a manual scale. Task definition
     * revision adoption is owned by `yolo deploy`, not sync.
     */
    public function needsUpdate(): bool
    {
        return $this->pendingChanges() !== [];
    }

    /**
     * The service-level attributes that have drifted from the manifest — read
     * live and diffed so the sync step can report each current → desired change.
     *
     * @return array<int, Change>
     */
    public function pendingChanges(): array
    {
        return static::serviceChanges(
            Ecs::service((new EcsCluster())->name(), $this->name()),
            $this->gracePeriod(),
            $this->enableExecuteCommand(),
            $this->reconcilesGracePeriod(),
        );
    }

    public static function serviceNeedsUpdate(array $service, int $gracePeriod, bool $enableExecuteCommand, bool $reconcilesGracePeriod = true): bool
    {
        return static::serviceChanges($service, $gracePeriod, $enableExecuteCommand, $reconcilesGracePeriod) !== [];
    }

    /**
     * Pure comparison — extracted so tests can pin headless / missing-grace-period
     * behaviour without mocking the ECS client. Exec-command drift is always
     * reconciled; the grace period only for an ALB-attached (web, non-headless)
     * service — a headless web app or a queue/scheduler worker has none.
     *
     * @return array<int, Change>
     */
    public static function serviceChanges(array $service, int $gracePeriod, bool $enableExecuteCommand, bool $reconcilesGracePeriod = true): array
    {
        $changes = [];

        $currentExecuteCommand = $service['enableExecuteCommand'] ?? false;

        if ($currentExecuteCommand !== $enableExecuteCommand) {
            $changes[] = Change::make('enableExecuteCommand', $currentExecuteCommand, $enableExecuteCommand);
        }

        if ($reconcilesGracePeriod) {
            $currentGracePeriod = $service['healthCheckGracePeriodSeconds'] ?? $gracePeriod;

            if ($currentGracePeriod !== $gracePeriod) {
                $changes[] = Change::make('healthCheckGracePeriodSeconds', $currentGracePeriod, $gracePeriod);
            }
        }

        return $changes;
    }

    public function update(): void
    {
        Aws::ecs()->updateService($this->updatePayload());
    }

    public function createPayload(): array
    {
        return [
            'cluster' => (new EcsCluster())->name(),
            'serviceName' => $this->name(),
            // The task definition family is the service name — SyncTaskDefinitionStep
            // registers the family from this same value. TaskDef isn't modelled as a
            // Resource (no taggable ARN to own; it's reconciled diff-first against the
            // latest revision), so the family is the service name rather than its own Resource.
            'taskDefinition' => $this->name(),
            // Capacity isn't a manifest concern — start at the group's floor and let
            // ops scale it (console / `yolo scale` / autoscaling); never reconciled.
            'desiredCount' => $this->initialDesiredCount(),
            ...$this->launchConfiguration(),
            ...$this->attachesToLoadBalancer() ? ['healthCheckGracePeriodSeconds' => $this->gracePeriod()] : [],
            'deploymentConfiguration' => $this->deploymentConfiguration(),
            'networkConfiguration' => [
                'awsvpcConfiguration' => [
                    'subnets' => PublicSubnet::ids(),
                    'securityGroups' => [(new EcsTaskSecurityGroup())->arn()],
                    'assignPublicIp' => 'ENABLED',
                ],
            ],
            ...$this->attachesToLoadBalancer() ? [
                'loadBalancers' => [
                    [
                        'targetGroupArn' => (new TargetGroup())->arn(),
                        'containerName' => $this->group->value,
                        'containerPort' => 8000,
                    ],
                ],
            ] : [],
            'tags' => Aws::ecsTags($this->tags()),
            'propagateTags' => 'SERVICE',
            'enableExecuteCommand' => $this->enableExecuteCommand(),
        ];
    }

    /**
     * FARGATE by default. A standalone queue can opt into Spot (`tasks.queue.spot:
     * true`) for ~70% cheaper interruptible capacity — fine for a worker whose
     * jobs retry on interruption. Spot uses a capacity-provider strategy, which is
     * mutually exclusive with launchType, so it's one or the other.
     *
     * @return array<string, mixed>
     */
    protected function launchConfiguration(): array
    {
        if ($this->group === ServerGroup::QUEUE && $this->spot()) {
            return ['capacityProviderStrategy' => [['capacityProvider' => 'FARGATE_SPOT', 'weight' => 1]]];
        }

        return ['launchType' => 'FARGATE'];
    }

    /**
     * Roll one task in at a time (minimumHealthyPercent 100 keeps the old version
     * serving until the new one is healthy; maximumPercent 200 allows the extra
     * task), with the deployment circuit breaker aborting and rolling back to the
     * last healthy revision on a failed rollout.
     *
     * The scheduler is the exception: it's a singleton, so it deploys stop-then-start
     * (minimumHealthyPercent 0 / maximumPercent 100) — the old cron task stops
     * before the new one starts, so a rollout never briefly runs two schedulers
     * (a missed cron minute is harmless; a double-run isn't). The circuit breaker
     * stays on either way — it's what makes ECS mark a broken deploy FAILED, the
     * signal WaitForDeploymentHealthyStep fast-fails on.
     *
     * @return array<string, mixed>
     */
    public function deploymentConfiguration(): array
    {
        return [
            'deploymentCircuitBreaker' => [
                'enable' => true,
                'rollback' => true,
            ],
            'minimumHealthyPercent' => $this->group->isSingleton() ? 0 : 100,
            'maximumPercent' => $this->group->isSingleton() ? 100 : 200,
        ];
    }

    public function updatePayload(): array
    {
        return [
            'cluster' => (new EcsCluster())->name(),
            'service' => $this->name(),
            'enableExecuteCommand' => $this->enableExecuteCommand(),
            // No desiredCount — capacity is create-only (see needsUpdate()).
            ...$this->attachesToLoadBalancer() ? ['healthCheckGracePeriodSeconds' => $this->gracePeriod()] : [],
        ];
    }

    public function enableExecuteCommand(): bool
    {
        return Helpers::validateStrictBool(
            Manifest::get("{$this->group->manifestPrefix()}.enable-execute-command", true),
            "{$this->group->manifestPrefix()}.enable-execute-command",
        );
    }

    public function gracePeriod(): int
    {
        return (int) Manifest::get('tasks.web.health-check.grace-period', 60);
    }

    /**
     * The desired count to create the service at. An autoscaling queue starts at its
     * floor (Manifest::queueMin) — 0 when it scales to zero, so a fresh idle queue
     * costs nothing; a fixed queue (autoscaling: false) and web and the scheduler all
     * start at one task.
     */
    protected function initialDesiredCount(): int
    {
        if ($this->group === ServerGroup::QUEUE && Manifest::autoscales(ServerGroup::QUEUE)) {
            return Manifest::queueMin();
        }

        return 1;
    }

    /**
     * Whether this service sits behind the ALB — web only. A web task always
     * attaches (it requires a domain, so the ALB path always exists).
     */
    protected function attachesToLoadBalancer(): bool
    {
        return $this->group->attachesToLoadBalancer();
    }

    protected function reconcilesGracePeriod(): bool
    {
        return $this->attachesToLoadBalancer();
    }

    protected function spot(): bool
    {
        return Helpers::validateStrictBool(
            Manifest::get('tasks.queue.spot', false),
            'tasks.queue.spot',
        );
    }
}
