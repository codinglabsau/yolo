<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\S3\S3Bucket;

class SyncS3BucketStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        // The app data bucket is optional. Skip when the manifest doesn't define
        // one; when it does, provision it (ConfigureEnvAndVersionStep injects it
        // as AWS_BUCKET).
        if (! Manifest::has('aws.bucket')) {
            return StepResult::SKIPPED;
        }

        $bucket = new S3Bucket();

        // Existing app buckets are left untouched — BPA and tags are applied only
        // at create, never reconciled (see S3Bucket).
        if ($bucket->exists()) {
            return StepResult::SYNCED;
        }

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_CREATE;
        }

        $bucket->create();

        return StepResult::CREATED;
    }
}
