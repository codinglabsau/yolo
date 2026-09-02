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
 * Typesense is the cluster's only occupant, so its lifecycle drives this step —
 * a second env-shared service moves it to the env tier's base list, gated on
 * any occupant. Teardown cascades the node services (see
 * ServicesCluster::delete()), which is why the node step's own teardown skips.
 */
class SyncServicesClusterStep implements LongRunning, Step
{
    use SynchronisesResource;

    /**
     * LongRunning for its teardown: deleting the cluster drains the node services
     * and waits for their tasks to stop (see ServicesCluster::delete()).
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
