<?php

namespace Codinglabs\Yolo\Steps\Recording;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncIvsRecordingBucketStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (! Manifest::ivsRecordingWebhookUrl()) {
            return StepResult::SKIPPED;
        }

        $bucket = self::bucketName();

        try {
            AwsResources::bucket($bucket);

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::s3()->createBucket(['Bucket' => $bucket]);
                Aws::s3()->waitUntil('BucketExists', ['Bucket' => $bucket]);
                Aws::s3()->putBucketTagging([
                    'Bucket' => $bucket,
                    'Tagging' => [...Aws::tags(['Name' => $bucket], wrap: 'TagSet')],
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }

    public static function bucketName(): string
    {
        return Helpers::keyedResourceName('ivs-recordings');
    }
}
