<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

class EnsureS3BucketExistsStep implements Step
{
    public function __invoke(): StepResult|string
    {
        $bucketName = Manifest::get('aws.bucket');

        if (! $bucketName) {
            return StepResult::SKIPPED;
        }

        if (! Aws::s3()->doesBucketExistV2($bucketName)) {
            Aws::s3()->createBucket([
                'Bucket' => $bucketName,
            ]);

            return StepResult::CREATED;
        }

        return "<info>$bucketName</info>";
    }
}
