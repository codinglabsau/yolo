<?php

namespace Codinglabs\Yolo\Resources\Ecs;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Kept apart from the per-app clusters so app liveness derivation
 * (`yolo-{env}-{app}`) never mistakes shared-service tasks for an app; `services`
 * is a reserved app name for the same reason. FARGATE only — the occupants are
 * stateful quorum members, not interruptible workers.
 */
class ServicesCluster implements Deletable, Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName('services');
    }

    public function scope(): Scope
    {
        return Scope::Env;
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
            'capacityProviders' => ['FARGATE'],
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
     * Same drain race as EcsCluster::delete(). The node-services step defers its own
     * teardown here so it's one atomic act with no cross-step ordering.
     */
    public function delete(): void
    {
        $serviceArns = Aws::ecs()->listServices([
            'cluster' => $this->name(),
        ])['serviceArns'] ?? [];

        foreach ($serviceArns as $serviceArn) {
            Aws::ecs()->updateService([
                'cluster' => $this->name(),
                'service' => $serviceArn,
                'desiredCount' => 0,
            ]);

            Aws::ecs()->deleteService([
                'cluster' => $this->name(),
                'service' => $serviceArn,
                'force' => true,
            ]);
        }

        Ecs::deleteClusterWhenDrained($this->name());
    }
}
