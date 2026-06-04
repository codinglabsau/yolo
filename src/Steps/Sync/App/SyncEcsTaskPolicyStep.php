<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\EcsTaskPolicy;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncEcsTaskPolicyStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        // Policy-document drift (e.g. a new statement added by a YOLO upgrade)
        // flows through syncResource as a plan-time Change via
        // SynchronisesPolicyDocument, so the plan flags it and apply re-versions
        // the policy — alongside the usual tag sync.
        return $this->syncResource(new EcsTaskPolicy(), $options);
    }
}
