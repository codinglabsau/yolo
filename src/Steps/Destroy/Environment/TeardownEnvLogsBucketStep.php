<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\S3\S3LogsBucket;

/**
 * Empties and deletes the env logs bucket (ALB access logs) — only with
 * --delete-data (see {@see RemoveDataBucketStep}).
 */
class TeardownEnvLogsBucketStep extends RemoveDataBucketStep
{
    protected function resource(): S3LogsBucket
    {
        return new S3LogsBucket();
    }
}
