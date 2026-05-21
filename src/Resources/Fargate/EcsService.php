<?php

namespace Codinglabs\Yolo\Resources\Fargate;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsLookups;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class EcsService implements Resource
{
    public function name(): string
    {
        return AwsLookups::ecsServiceName();
    }

    public function tags(): array
    {
        return ['Name' => $this->name()];
    }

    public function exists(): bool
    {
        try {
            AwsLookups::ecsService();

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return AwsLookups::ecsService()['serviceArn'];
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
     * Service-config drift (desired count + grace period) reconciled by
     * updateService. Task definition revision adoption is owned by
     * `yolo deploy`, not sync — sync reconciles only slow-moving knobs.
     */
    public function needsUpdate(): bool
    {
        return static::serviceNeedsUpdate(
            AwsLookups::ecsService(),
            $this->desiredCount(),
            $this->gracePeriod(),
        );
    }

    /**
     * Pure comparison — extracted so tests can pin headless / missing-grace-period
     * behaviour without mocking the ECS client.
     */
    public static function serviceNeedsUpdate(array $service, int $desiredCount, int $gracePeriod): bool
    {
        if ($service['desiredCount'] !== $desiredCount) {
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
            'cluster' => AwsLookups::ecsClusterName(),
            'serviceName' => $this->name(),
            'taskDefinition' => AwsLookups::ecsTaskFamily(),
            'desiredCount' => $this->desiredCount(),
            'launchType' => 'FARGATE',
            ...Manifest::isHeadless() ? [] : ['healthCheckGracePeriodSeconds' => $this->gracePeriod()],
            'deploymentConfiguration' => [
                'minimumHealthyPercent' => 100,
                'maximumPercent' => 200,
            ],
            'networkConfiguration' => [
                'awsvpcConfiguration' => [
                    'subnets' => AwsLookups::publicSubnetIds(),
                    'securityGroups' => [AwsLookups::ecsTaskSecurityGroup()['GroupId']],
                    'assignPublicIp' => 'ENABLED',
                ],
            ],
            ...Manifest::isHeadless() ? [] : [
                'loadBalancers' => [
                    [
                        'targetGroupArn' => AwsLookups::targetGroup()['TargetGroupArn'],
                        'containerName' => 'web',
                        'containerPort' => (int) Manifest::get('tasks.web.port', 8000),
                    ],
                ],
            ],
            'tags' => Aws::ecsTags($this->tags()),
            'propagateTags' => 'SERVICE',
            'enableExecuteCommand' => Helpers::validateStrictBool(
                Manifest::get('tasks.web.enable-execute-command', false),
                'tasks.web.enable-execute-command',
            ),
        ];
    }

    public function updatePayload(): array
    {
        return [
            'cluster' => AwsLookups::ecsClusterName(),
            'service' => $this->name(),
            'desiredCount' => $this->desiredCount(),
            ...Manifest::isHeadless() ? [] : ['healthCheckGracePeriodSeconds' => $this->gracePeriod()],
        ];
    }

    public function desiredCount(): int
    {
        return (int) Manifest::get('tasks.web.desired-count', 1);
    }

    public function gracePeriod(): int
    {
        return (int) Manifest::get('tasks.web.health-check.grace-period', 60);
    }
}
