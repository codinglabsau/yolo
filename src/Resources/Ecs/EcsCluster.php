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
     * Teardown cascades: AWS refuses to delete a cluster while it still has an
     * active service or a running task, so every remaining service is
     * force-deleted first, then we wait for them to drain. `force` deletes a
     * service asynchronously — its tasks keep stopping over the graceful-drain
     * window — so without the `ServicesInactive` wait `deleteCluster` would race
     * the drain and throw `ClusterContainsTasksException`. listServices returns
     * ACTIVE + DRAINING services, so a service the per-app teardown steps already
     * force-deleted is still caught here while it drains. (Reachable as the
     * safety-net sweep: the app's web/queue/scheduler services are normally torn
     * down by their own steps ahead of this.)
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

        if ($serviceArns !== []) {
            Aws::waitFor(Aws::ecs(), 'ServicesInactive', [
                'cluster' => $this->name(),
                'services' => $serviceArns,
            ]);
        }

        Aws::ecs()->deleteCluster([
            'cluster' => $this->name(),
        ]);
    }
}
