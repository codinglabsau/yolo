<?php

namespace Codinglabs\Yolo\Resources\Fargate;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class EcsCluster implements Resource
{
    public function name(): string
    {
        return AwsResources::ecsClusterName();
    }

    public function tags(): array
    {
        return ['Name' => $this->name()];
    }

    public function exists(): bool
    {
        try {
            AwsResources::ecsCluster();

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return AwsResources::ecsCluster()['clusterArn'];
    }

    public function create(): void
    {
        Aws::ecs()->createCluster([
            'clusterName' => $this->name(),
            'capacityProviders' => ['FARGATE', 'FARGATE_SPOT'],
            'defaultCapacityProviderStrategy' => [
                ['capacityProvider' => 'FARGATE', 'weight' => 1, 'base' => 1],
            ],
            'settings' => [
                ['name' => 'containerInsights', 'value' => 'enabled'],
            ],
            'tags' => Aws::ecsTags($this->tags()),
        ]);
    }

    public function synchroniseTags(): void
    {
        Aws::synchroniseEcsTags($this->arn(), $this->tags());
    }
}
