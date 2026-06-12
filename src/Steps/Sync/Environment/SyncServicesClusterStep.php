<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
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
class SyncServicesClusterStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return match (Lifecycle::state(Service::TYPESENSE)) {
            ServiceState::Provision => $this->syncResource(new ServicesCluster(), $options),
            ServiceState::Teardown => $this->teardownResource(new ServicesCluster(), $options),
            ServiceState::Retain => StepResult::SKIPPED,
        };
    }
}
