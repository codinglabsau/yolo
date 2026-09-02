<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\ObserverPolicy;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

/**
 * Unconditional (not gated on a GitHub repo like the deployer steps): every
 * app's deployer role attaches it for the pre-deploy `sync --check`, and an
 * operator/admin role can reuse it.
 */
class SyncObserverPolicyStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new ObserverPolicy(), $options);
    }
}
