<?php

namespace Codinglabs\Yolo\Steps\Storage;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Storage\AssetBucket;

class SyncAssetBucketStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::get('assets.cloudfront')) {
            return StepResult::SKIPPED;
        }

        return $this->syncResource(new AssetBucket(), $options);
    }
}
