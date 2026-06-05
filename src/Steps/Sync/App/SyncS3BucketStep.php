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
        // one; when it does, provision it (ConfigureEnvAndVersionStep injects it
        // as AWS_BUCKET).
        if (! Manifest::has('bucket')) {
            return StepResult::SKIPPED;
        }

        // Create-or-sync: an existing bucket has its CORS (browser-upload ruleset)
        // and tags reconciled; Block Public Access stays create-only (it lives in
        // S3Bucket::create(), never in the sync path), so a live public bucket is
        // never flipped under foot.
        return $this->syncResource(new S3Bucket(), $options);
    }
}
