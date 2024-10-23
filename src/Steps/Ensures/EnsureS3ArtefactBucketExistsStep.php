<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

class EnsureS3ArtefactBucketExistsStep implements Step
{
    public function __invoke(): StepResult
    {
        $bucketName = Helpers::keyedResourceName('artefacts');

        if (Paths::s3ArtefactsBucket() && Aws::s3()->doesBucketExistV2($bucketName)) {
            return StepResult::SYNCED;
        }

        if (! Aws::s3()->doesBucketExistV2($bucketName)) {
            Aws::s3()->createBucket([
                'Bucket' => $bucketName,
            ]);
        }

        Manifest::put('aws.artefacts-bucket', $bucketName);

        return StepResult::CREATED;
    }
}
