<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\S3\S3LogsBucket;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncS3LogsBucketStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new S3LogsBucket(), $options);
    }
}
