<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\S3\S3Bucket;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncS3BucketStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::has('bucket')) {
            return StepResult::SKIPPED;
        }

        // A bring-your-own bucket is adopt-only: existence is verified pre-plan by
        // Command::ensureAppBucketAdoptable, and YOLO never writes to a bucket it doesn't own.
        if (! Manifest::managesAppBucket()) {
            return StepResult::SKIPPED;
        }

        return $this->syncResource(new S3Bucket(), $options);
    }
}
