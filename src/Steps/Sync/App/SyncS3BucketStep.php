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
        // The app data bucket is optional. Skip when the manifest doesn't define
        // one (ConfigureEnvAndVersionStep injects it as AWS_BUCKET when it does).
        if (! Manifest::has('bucket')) {
            return StepResult::SKIPPED;
        }

        // A bring-your-own bucket is adopt-only: nothing to create (its existence on
        // this account is verified before the plan, by
        // Command::ensureAppBucketAdoptable) and nothing to reconcile, since YOLO
        // never writes to a bucket it doesn't own.
        if (! Manifest::managesAppBucket()) {
            return StepResult::SKIPPED;
        }

        return $this->syncResource(new S3Bucket(), $options);
    }
}
