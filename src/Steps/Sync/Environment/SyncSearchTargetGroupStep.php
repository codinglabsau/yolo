<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\ElbV2\SearchTargetGroup;

/**
 * Provisioned before the node services so they can attach at create. A
 * declared typesense without an env domain is a hard error (requireSearchHost
 * names the fix), never a silently-private cluster.
 */
class SyncSearchTargetGroupStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return match (Lifecycle::state(Service::TYPESENSE)) {
            ServiceState::Provision => $this->provision($options),
            ServiceState::Teardown => $this->teardownResource(new SearchTargetGroup(), $options),
        };
    }

    protected function provision(array $options): StepResult
    {
        Typesense::requireSearchHost();

        return $this->syncResource(new SearchTargetGroup(), $options);
    }
}
