<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\S3\S3ConfigBucket;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncS3ConfigBucketStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new S3ConfigBucket(), $options);
    }
}
