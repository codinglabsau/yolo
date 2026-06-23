<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\S3\S3LogsBucket;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

/**
 * Empties and deletes the env logs bucket (ALB access logs) — expiring telemetry
 * that goes with the environment it belongs to.
 */
class TeardownEnvLogsBucketStep extends TeardownStep
{
    protected function resource(): S3LogsBucket
    {
        return new S3LogsBucket();
    }
}
