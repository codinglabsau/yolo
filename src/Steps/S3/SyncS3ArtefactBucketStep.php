<?php

namespace Codinglabs\Yolo\Steps\S3;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncS3ArtefactBucketStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        $bucketName = Paths::s3ArtefactsBucket();

        try {
            AwsResources::bucket($bucketName);
            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException $e) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::s3()->createBucket([
                    'Bucket' => $bucketName,
                ]);

                Aws::s3()->waitUntil('BucketExists', [
                    'Bucket' => $bucketName,
                ]);

                Aws::s3()->putBucketTagging([
                    'Bucket' => $bucketName,
                    'Tagging' => [
                        ...Aws::tags([
                            'Name' => $bucketName,
                        ], wrap: 'TagSet'),
                    ],
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
