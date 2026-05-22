<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;

class S3
{
    public static function bucketExists(string $name): bool
    {
        return Aws::s3()->doesBucketExistV2($name);
    }
}
