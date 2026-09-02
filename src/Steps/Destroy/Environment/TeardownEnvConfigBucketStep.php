<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\S3\EnvConfigBucket;

/**
 * Regeneratable infrastructure config, so it goes with the environment — unlike
 * the bring-your-own app data bucket, which isn't even Deletable.
 */
class TeardownEnvConfigBucketStep extends TeardownStep
{
    protected function resource(): EnvConfigBucket
    {
        return new EnvConfigBucket();
    }
}
