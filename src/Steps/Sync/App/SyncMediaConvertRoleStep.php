<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

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
        if (! Manifest::get('mediaconvert')) {
            return StepResult::SKIPPED;
        }

        // Trust-policy drift rides through SynchronisesConfiguration on the role.
        return $this->syncResource(new MediaConvertRole(), $options);
    }
}
