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
            $cluster = AwsResources::ecsCluster();

            $this->reconcileTags($cluster['clusterArn'], Arr::get($options, 'dry-run'));

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

    protected function reconcileTags(string $arn, bool $dryRun): void
    {
        $current = Aws::flattenTags(Aws::ecs()->listTagsForResource([
            'resourceArn' => $arn,
        ])['tags']);

        $missing = Aws::tagsRequiringSync(
            Aws::expectedTags(['Name' => AwsResources::ecsClusterName()]),
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
}
