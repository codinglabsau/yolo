<?php

namespace Codinglabs\Yolo\Steps\Storage;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Storage\S3ArtefactBucket;

class SyncS3ArtefactBucketStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new S3ArtefactBucket(), $options);
    }
}
