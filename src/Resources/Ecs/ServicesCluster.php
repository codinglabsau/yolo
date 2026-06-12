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
 * The environment's shared services cluster (`yolo-{env}-services`) — home of
 * every env-shared service task (the Typesense nodes today), kept apart from
 * the per-app clusters so app liveness derivation (`yolo-{env}-{app}`) never
 * mistakes shared-service tasks for an app. `services` is a reserved app name
 * for the same reason. FARGATE only — no Spot: the occupants are stateful
 * quorum members, not interruptible workers.
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
     * Teardown cascades: AWS refuses to delete a cluster with active services,
     * so every service is drained (desired 0) and force-deleted first. The
     * node-services step defers its own teardown here for the same reason the
     * IVS target defers to its rule — one atomic act, no cross-step ordering.
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

        Aws::ecs()->deleteCluster([
            'cluster' => $this->name(),
        ]);
    }
}
