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
 * The search target group — provisioned before the node services so they can
 * attach to it at create. Requires the env manifest's domain: a search host
 * is the whole point of the public path, so a declared typesense without a
 * domain is a hard error (requireSearchHost names the fix), never a
 * silently-private cluster.
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
