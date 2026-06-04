<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\EcsTaskRole;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncEcsTaskRoleStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $role = new EcsTaskRole();

        // Trust-policy drift reconciled by replacing the assume-role policy.
        if ($role->exists() && ! Arr::get($options, 'dry-run')) {
            $role->synchroniseAssumeRolePolicy();
        }

        return $this->syncResource($role, $options);
    }
}
