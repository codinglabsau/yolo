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
 * One service + task-definition family per group so they scale independently. Web
 * attaches to the ALB; queue and scheduler are headless. The scheduler is a
 * singleton deployed stop-then-start so a rollout never runs two crons. Desired
 * count is create-only, owned by ops/autoscaling afterwards — sync never clobbers it.
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
        // The web target group is attached to the listener only a few steps earlier and
        // ELB→ECS consistency can lag; headless services have no target group, so the
        // helper never retries them.
        Ecs::createServiceWhenTargetGroupAttached($this->createPayload());
    }

    /** `force` drains and removes in one call; already gone or mid-deletion is the goal state. */
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
     * Desired count is NOT reconciled — owned by ops (console, `yolo scale`,
     * autoscaling) after create, so a sync never resets a manual scale. Task
     * definition revision adoption belongs to `yolo deploy`, not sync.
     */
    public function needsUpdate(): bool
    {
        return $this->pendingChanges() !== [];
    }

    /**
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
     * Pure, so tests can pin behaviour without mocking the ECS client. The grace
     * period is reconciled only for an ALB-attached service — headless ones have none.
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
            // The family is the service name — SyncTaskDefinitionStep registers from the same value.
            'taskDefinition' => $this->name(),
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
     * Spot is fine for a worker whose jobs retry on interruption. It uses a
     * capacity-provider strategy, which is mutually exclusive with launchType.
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
     * The singleton scheduler deploys stop-then-start (a missed cron minute is
     * harmless; a double-run isn't). The circuit breaker is what makes ECS mark a
     * broken deploy FAILED — the signal WaitForDeploymentHealthyStep fast-fails on.
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
            // No desiredCount — create-only (see needsUpdate()).
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

    protected function initialDesiredCount(): int
    {
        if ($this->group === ServerGroup::QUEUE && Manifest::autoscales(ServerGroup::QUEUE)) {
            return Manifest::queueMin();
        }

        return 1;
    }

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
