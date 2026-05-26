<?php

namespace Codinglabs\Yolo\Resources\Fargate;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\AppScoped;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class EcsService implements AppScoped, Resource
{
    use ResolvesTags;

    protected const INITIAL_DESIRED_COUNT = 1;

    public function name(): string
    {
        return Helpers::keyedResourceName('web', exclusive: true);
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
        Aws::ecs()->createService($this->createPayload());
    }

    public function synchroniseTags(): void
    {
        Aws::synchroniseEcsTags($this->arn(), $this->tags());
    }

    /**
     * Exec-command and grace-period drift are reconciled by updateService, so
     * toggling tasks.web.enable-execute-command takes effect on the next sync.
     * Desired count is NOT reconciled — capacity is set once at create then owned
     * by ops (the console, a future `yolo scale`, or autoscaling), so a deploy/sync
     * never resets it out from under a manual scale. Task definition revision
     * adoption is owned by `yolo deploy`, not sync.
     */
    public function needsUpdate(): bool
    {
        return static::serviceNeedsUpdate(
            Ecs::service((new EcsCluster())->name(), $this->name()),
            $this->gracePeriod(),
            $this->enableExecuteCommand(),
        );
    }

    /**
     * Pure comparison — extracted so tests can pin headless / missing-grace-period
     * behaviour without mocking the ECS client.
     */
    public static function serviceNeedsUpdate(array $service, int $gracePeriod, bool $enableExecuteCommand): bool
    {
        if (($service['enableExecuteCommand'] ?? false) !== $enableExecuteCommand) {
            return true;
        }

        // Headless services have no ALB association and therefore no grace period to reconcile.
        if (Manifest::isHeadless()) {
            return false;
        }

        return ($service['healthCheckGracePeriodSeconds'] ?? $gracePeriod) !== $gracePeriod;
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
            // The task definition family is the web service name — SyncTaskDefinitionStep
            // registers the family from this same value. TaskDef doesn't fit the Resource
            // shape (re-registered every sync, no exists/create distinction), so the family
            // is the service name rather than its own Resource.
            'taskDefinition' => $this->name(),
            // Capacity isn't a manifest concern — start at one task and let ops
            // scale it (console / `yolo scale` / autoscaling); never reconciled.
            'desiredCount' => self::INITIAL_DESIRED_COUNT,
            'launchType' => 'FARGATE',
            ...Manifest::isHeadless() ? [] : ['healthCheckGracePeriodSeconds' => $this->gracePeriod()],
            'deploymentConfiguration' => [
                'minimumHealthyPercent' => 100,
                'maximumPercent' => 200,
            ],
            'networkConfiguration' => [
                'awsvpcConfiguration' => [
                    // Public subnet IDs still come from the legacy AwsResources facade — LPX-612.
                    'subnets' => AwsResources::publicSubnetIds(),
                    'securityGroups' => [(new EcsTaskSecurityGroup())->arn()],
                    'assignPublicIp' => 'ENABLED',
                ],
            ],
            ...Manifest::isHeadless() ? [] : [
                'loadBalancers' => [
                    [
                        'targetGroupArn' => (new TargetGroup())->arn(),
                        'containerName' => 'web',
                        'containerPort' => (int) Manifest::get('tasks.web.port', 8000),
                    ],
                ],
            ],
            'tags' => Aws::ecsTags($this->tags()),
            'propagateTags' => 'SERVICE',
            'enableExecuteCommand' => $this->enableExecuteCommand(),
        ];
    }

    public function updatePayload(): array
    {
        return [
            'cluster' => (new EcsCluster())->name(),
            'service' => $this->name(),
            'enableExecuteCommand' => $this->enableExecuteCommand(),
            // No desiredCount — capacity is create-only (see needsUpdate()).
            ...Manifest::isHeadless() ? [] : ['healthCheckGracePeriodSeconds' => $this->gracePeriod()],
        ];
    }

    public function enableExecuteCommand(): bool
    {
        return Helpers::validateStrictBool(
            Manifest::get('tasks.web.enable-execute-command', false),
            'tasks.web.enable-execute-command',
        );
    }

    public function gracePeriod(): int
    {
        return (int) Manifest::get('tasks.web.health-check.grace-period', 60);
    }
}
