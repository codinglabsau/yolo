<?php

namespace Codinglabs\Yolo\Resources\Ecs;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class EcsCluster implements Deletable, Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName();
    }

    public function scope(): Scope
    {
        return Scope::App;
    }

    public function exists(): bool
    {
        try {
            Ecs::cluster($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return Ecs::cluster($this->name())['clusterArn'];
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

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseEcsTags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Teardown cascades: AWS refuses to delete a cluster with active services,
     * so every remaining service is drained (desired 0) and force-deleted first,
     * then the cluster itself. The app's web/queue/scheduler services are torn
     * down by their own steps ahead of this, so this normally finds an empty
     * cluster — the sweep is the safety net for anything left attached.
     */
    public function delete(): void
    {
        $serviceArns = Aws::ecs()->listServices([
            'cluster' => $this->name(),
        ])['serviceArns'] ?? [];

        foreach ($serviceArns as $serviceArn) {
            Aws::ecs()->deleteService([
                'cluster' => $this->name(),
                'service' => $serviceArn,
                'force' => true,
            ]);
        }

        Aws::ecs()->deleteCluster([
            'cluster' => $this->name(),
        ]);
    }
}
