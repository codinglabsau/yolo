<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
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
            $needsUpdate = static::needsUpdate($service, $desiredCount, $gracePeriod);

            if ($needsUpdate && Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_SYNC;
            }

            if ($needsUpdate) {
                Aws::ecs()->updateService(static::updatePayload($desiredCount, $gracePeriod));
            }

            $this->reconcileTags($service['serviceArn'], Arr::get($options, 'dry-run'));

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_CREATE;
            }

            Aws::ecs()->createService(static::createPayload($desiredCount, $gracePeriod));

            return StepResult::CREATED;
        }
    }

    protected function reconcileTags(string $arn, bool $dryRun): void
    {
        $current = Aws::flattenTags(
            Aws::ecs()->listTagsForResource(['resourceArn' => $arn])['tags'] ?? []
        );

        $missing = Aws::tagsRequiringSync(
            Aws::expectedTags(['Name' => AwsResources::ecsServiceName()]),
            $current,
        );

        if (empty($missing) || $dryRun) {
            return;
        }

        Aws::ecs()->tagResource([
            'resourceArn' => $arn,
            'tags' => collect($missing)
                ->map(fn ($value, $key) => ['key' => $key, 'value' => $value])
                ->values()
                ->all(),
        ]);
    }

    public static function needsUpdate(array $service, int $desiredCount, int $gracePeriod): bool
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

    public static function createPayload(int $desiredCount, int $gracePeriod): array
    {
        return [
            'cluster' => AwsResources::ecsClusterName(),
            'serviceName' => AwsResources::ecsServiceName(),
            'taskDefinition' => AwsResources::ecsTaskFamily(),
            'desiredCount' => $desiredCount,
            'launchType' => 'FARGATE',
            ...Manifest::isHeadless() ? [] : ['healthCheckGracePeriodSeconds' => $gracePeriod],
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
            ...Manifest::isHeadless() ? [] : [
                'loadBalancers' => [
                    [
                        'targetGroupArn' => AwsResources::targetGroup()['TargetGroupArn'],
                        'containerName' => 'web',
                        'containerPort' => (int) Manifest::get('tasks.web.port', 8000),
                    ],
                ],
            ],
            'tags' => Aws::ecsTags(['Name' => AwsResources::ecsServiceName()]),
            'propagateTags' => 'SERVICE',
            'enableExecuteCommand' => Helpers::validateStrictBool(
                Manifest::get('tasks.web.enable-execute-command', false),
                'tasks.web.enable-execute-command',
            ),
        ];
    }

    public static function updatePayload(int $desiredCount, int $gracePeriod): array
    {
        return [
            'cluster' => AwsResources::ecsClusterName(),
            'service' => AwsResources::ecsServiceName(),
            'desiredCount' => $desiredCount,
            ...Manifest::isHeadless() ? [] : ['healthCheckGracePeriodSeconds' => $gracePeriod],
        ];
    }
}
