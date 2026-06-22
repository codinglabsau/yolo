<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\S3\EnvConfigBucket;

/**
 * Empties and deletes the env config bucket (the env manifest + env-shared .env +
 * any residual app claim/env files) — the last act of an environment teardown.
 * Only with --delete-data (see {@see RemoveDataBucketStep}).
 */
class TeardownEnvConfigBucketStep extends RemoveDataBucketStep
{
    protected function resource(): EnvConfigBucket
    {
        return new EnvConfigBucket();
    }
}
