<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Iam\EcsExecutionRole;

class SyncEcsExecutionRoleStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $role = new EcsExecutionRole();

        // Trust-policy drift reconciled by replacing the assume-role policy.
        if ($role->exists() && ! Arr::get($options, 'dry-run')) {
            $role->synchroniseAssumeRolePolicy();
        }

        return $this->syncResource($role, $options);
    }
}
