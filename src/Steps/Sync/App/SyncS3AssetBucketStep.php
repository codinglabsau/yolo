<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\S3\AssetBucket;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncS3AssetBucketStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new AssetBucket(), $options);
    }
}
