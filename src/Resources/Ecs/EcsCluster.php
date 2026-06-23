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
     * active service OR a non-STOPPED task. Any service still listed is
     * force-deleted and drained first as a safety-net sweep — listServices
     * returns ACTIVE + DRAINING services, so one mid-drain is still caught here.
     *
     * But the app's own web/queue/scheduler services are normally torn down by
     * their own steps *ahead* of this, and a force-deleted service drops off
     * listServices the instant it enters DRAINING — well before its tasks finish
     * stopping over the graceful-drain window. So by the time we reach here there
     * is often no service left to wait on, yet tasks are still STOPPING. The real
     * precondition for DeleteCluster is "no active tasks", not "no active
     * services" — and `ServicesInactive` flips once tasks are STOPPING-or-STOPPED,
     * not fully stopped — so the delete itself is retried against AWS's own check
     * until the drain completes (see Ecs::deleteClusterWhenDrained).
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

        Ecs::deleteClusterWhenDrained($this->name());
    }
}
