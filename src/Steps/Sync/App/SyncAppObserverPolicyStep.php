<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Iam\AppObserverPolicy;

class SyncAppObserverPolicyStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        // Policy-document drift (a YOLO upgrade that changed the read surface, or
        // a changed log-group name) flows through syncResource as a plan-time
        // Change via SynchronisesPolicyDocument, so the plan flags it and apply
        // re-versions the policy.
        return $this->syncResource(new AppObserverPolicy(), $options);
    }
}
