<?php

namespace Codinglabs\Yolo\Steps\Network;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncS3BucketStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        $bucketName = Manifest::get('aws.bucket');

        if (! $bucketName) {
            return StepResult::SKIPPED;
        }

        try {
            AwsResources::bucket($bucketName);
            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException $e) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::s3()->createBucket([
                    'Bucket' => $bucketName,
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
