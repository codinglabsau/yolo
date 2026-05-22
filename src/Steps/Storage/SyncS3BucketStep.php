<?php

namespace Codinglabs\Yolo\Steps\Storage;

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
        // The app-side bucket is optional. Skip when the manifest doesn't define
        // one; when it does, provision it (and ConfigureEnvAndVersionStep injects
        // it as AWS_BUCKET).
        if (! Manifest::has('aws.bucket')) {
            return StepResult::SKIPPED;
        }

        $bucketName = Paths::s3AppBucket();

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

                // Secure-by-default for new app buckets. Deliberately NOT reconciled
                // onto existing buckets — an app may already serve public objects, and
                // flipping Block Public Access under it would break that.
                Aws::s3()->putPublicAccessBlock([
                    'Bucket' => $bucketName,
                    'PublicAccessBlockConfiguration' => [
                        'BlockPublicAcls' => true,
                        'IgnorePublicAcls' => true,
                        'BlockPublicPolicy' => true,
                        'RestrictPublicBuckets' => true,
                    ],
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
