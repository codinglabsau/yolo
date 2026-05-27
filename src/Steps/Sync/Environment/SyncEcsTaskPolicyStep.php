<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\EcsTaskPolicy;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncEcsTaskPolicyStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $policy = new EcsTaskPolicy();

        // Policy-document drift (the ssmmessages:* statement) reconciled by
        // creating a new policy version.
        if ($policy->exists() && ! Arr::get($options, 'dry-run')) {
            $policy->synchroniseDocument();
        }

        return $this->syncResource($policy, $options);
    }
}
