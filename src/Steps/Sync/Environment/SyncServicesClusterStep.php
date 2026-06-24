<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Destroying;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Ecs\ServicesCluster;

/**
 * The env services cluster. Typesense is its first (and so far only)
 * occupant, so the typesense lifecycle drives it — when a second env-shared
 * service lands on the cluster, this step moves to the env tier's base list
 * and gates on any occupant being provisioned. Teardown cascades the node
 * services (see ServicesCluster::delete()), which is why the node step's own
 * teardown is a deliberate skip.
 */
class SyncServicesClusterStep implements LongRunning, Step
{
    use SynchronisesResource;

    /**
     * LongRunning for its teardown: deleting the cluster drains the node services
     * first and waits for their tasks to stop before the cluster delete is accepted
     * (see ServicesCluster::delete() → Ecs::deleteClusterWhenDrained()), which can
     * take a few minutes. Creating the cluster is immediate, so the patience line
     * reflects whichever direction is running.
     */
    public function patienceMessage(): string
    {
        return Destroying::active()
            ? 'Draining the search nodes and removing their cluster — waiting for the tasks to stop (up to a few minutes).'
            : 'Setting up the search services cluster.';
    }

    public function __invoke(array $options): StepResult
    {
        return match (Lifecycle::state(Service::TYPESENSE)) {
            ServiceState::Provision => $this->syncResource(new ServicesCluster(), $options),
            ServiceState::Teardown => $this->teardownResource(new ServicesCluster(), $options),
        };
    }
}
