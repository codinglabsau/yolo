<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\DeployerRole;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncDeployerRoleStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (Helpers::githubRepository() === null) {
            return StepResult::SKIPPED;
        }

        $role = new DeployerRole();

        // Trust-policy drift (the manifest repository/branch changed) reconciled
        // by replacing the assume-role policy.
        if ($role->exists() && ! Arr::get($options, 'dry-run')) {
            $role->synchroniseAssumeRolePolicy();
        }

        return $this->syncResource($role, $options);
    }
}
