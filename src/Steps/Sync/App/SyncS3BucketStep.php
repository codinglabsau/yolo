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

        // Create-only: a missing bucket is created with its attributes (BPA, CORS,
        // tags) set once; an existing or brought-in bucket is left completely
        // alone — no reconcile — so YOLO needs no S3 permission on a bucket it
        // doesn't own (S3Bucket::synchroniseTags is a no-op and it's not a
        // SynchronisesConfiguration, so syncResource is a clean no-op when it exists).
        return $this->syncResource(new S3Bucket(), $options);
    }
}
