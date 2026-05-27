<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Iam\MediaConvertRole;

class SyncMediaConvertRoleStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::get('aws.mediaconvert')) {
            return StepResult::SKIPPED;
        }

        $role = new MediaConvertRole();

        // Trust-policy drift reconciled by replacing the assume-role policy.
        if ($role->exists() && ! Arr::get($options, 'dry-run')) {
            $role->synchroniseAssumeRolePolicy();
        }

        return $this->syncResource($role, $options);
    }
}
