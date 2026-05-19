<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncEcsServiceStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        $desiredCount = (int) Manifest::get('tasks.web.desired-count', 1);
        $gracePeriod = (int) Manifest::get('tasks.web.health-check.grace-period', 60);

        try {
            $service = AwsResources::ecsService();

            // Task definition revision adoption is owned by `yolo deploy`, not sync —
            // sync reconciles only the slow-moving service-level knobs.
            $needsUpdate = $service['desiredCount'] !== $desiredCount
                || ($service['healthCheckGracePeriodSeconds'] ?? $gracePeriod) !== $gracePeriod;

            if (! $needsUpdate) {
                return StepResult::SYNCED;
            }

            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_SYNC;
            }

            Aws::ecs()->updateService([
                'cluster' => AwsResources::ecsClusterName(),
                'service' => AwsResources::ecsServiceName(),
                'desiredCount' => $desiredCount,
                'healthCheckGracePeriodSeconds' => $gracePeriod,
            ]);

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_CREATE;
            }

            Aws::ecs()->createService([
                'cluster' => AwsResources::ecsClusterName(),
                'serviceName' => AwsResources::ecsServiceName(),
                'taskDefinition' => AwsResources::ecsTaskFamily(),
                'desiredCount' => $desiredCount,
                'launchType' => 'FARGATE',
                'healthCheckGracePeriodSeconds' => $gracePeriod,
                'deploymentConfiguration' => [
                    'minimumHealthyPercent' => 100,
                    'maximumPercent' => 200,
                ],
                'networkConfiguration' => [
                    'awsvpcConfiguration' => [
                        'subnets' => AwsResources::publicSubnetIds(),
                        'securityGroups' => [AwsResources::ecsTaskSecurityGroup()['GroupId']],
                        'assignPublicIp' => 'ENABLED',
                    ],
                ],
                'loadBalancers' => [
                    [
                        'targetGroupArn' => AwsResources::targetGroup()['TargetGroupArn'],
                        'containerName' => 'web',
                        'containerPort' => (int) Manifest::get('tasks.web.port', 8000),
                    ],
                ],
                'tags' => Aws::ecsTags(['Name' => AwsResources::ecsServiceName()]),
                'propagateTags' => 'SERVICE',
                'enableExecuteCommand' => (bool) Manifest::get('tasks.web.enable-execute-command', false),
            ]);

            return StepResult::CREATED;
        }
    }
}
