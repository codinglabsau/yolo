<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

trait UsesS3
{
    public static function bucket(string $name): bool
    {
        if (Aws::s3()->doesBucketExistV2($name)) {
            return true;
        }

        throw new ResourceDoesNotExistException("Could not find bucket with name $name");
    }
}
