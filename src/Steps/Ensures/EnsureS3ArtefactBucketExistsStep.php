<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;

class EnsureS3ArtefactBucketExistsStep implements Step
{
    public function __invoke(): string
    {
        $bucketName = Helpers::keyedResourceName('artefacts');

        if (Paths::s3ArtefactsBucket() && Aws::s3()->doesBucketExistV2($bucketName)) {
            return $bucketName;
        }

        if (! Aws::s3()->doesBucketExistV2($bucketName)) {
            Aws::s3()->createBucket([
                'Bucket' => $bucketName,
            ]);
        }

        return $bucketName;
    }
}
