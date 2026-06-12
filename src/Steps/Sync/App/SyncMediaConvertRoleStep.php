<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Iam\MediaConvertRole;

class SyncMediaConvertRoleStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        // Dropping the claim melts the per-app service IAM in the same pass —
        // the role (and its policy attachments, detached inside delete()) goes
        // when the app stops consuming mediaconvert. Never-claimed apps skip.
        if (! Manifest::usesService(Service::MEDIA_CONVERT)) {
            return $this->teardownResource(new MediaConvertRole(), $options);
        }

        // Trust-policy drift rides through SynchronisesConfiguration on the role.
        return $this->syncResource(new MediaConvertRole(), $options);
    }
}
