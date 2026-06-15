<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\YoloObserver;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

/**
 * Provisions the env-shared `yolo-{env}-observer` read-only policy. Unconditional
 * (not gated on a GitHub repo like the deployer steps): it's the inspection surface
 * every app's deployer role attaches to run the pre-deploy `sync --check`, and is
 * reusable by an operator/admin role too, so it stands up with the environment.
 *
 * Document drift (a YOLO upgrade that reads a new service) surfaces as a plan-time
 * Change via SynchronisesPolicyDocument, so the plan flags it and apply re-versions.
 */
class SyncYoloObserverPolicyStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new YoloObserver(), $options);
    }
}
