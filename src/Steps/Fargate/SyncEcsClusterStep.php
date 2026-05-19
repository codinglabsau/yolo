<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncEcsClusterStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::ecsCluster();

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_CREATE;
            }

            Aws::ecs()->createCluster([
                'clusterName' => AwsResources::ecsClusterName(),
                'capacityProviders' => ['FARGATE', 'FARGATE_SPOT'],
                'defaultCapacityProviderStrategy' => [
                    ['capacityProvider' => 'FARGATE', 'weight' => 1, 'base' => 1],
                ],
                'settings' => [
                    ['name' => 'containerInsights', 'value' => 'enabled'],
                ],
                'tags' => Aws::ecsTags(['Name' => AwsResources::ecsClusterName()]),
            ]);

            return StepResult::CREATED;
        }
    }
}
