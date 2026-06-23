<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\S3\EnvConfigBucket;

/**
 * Empties and deletes the env config bucket (the env manifest + env-shared .env +
 * any residual app claim/env files) — the last act of an environment teardown. The
 * bucket is regeneratable infrastructure config, so it goes with the environment.
 * (The bring-your-own app data bucket is never touched — it isn't even Deletable.)
 */
class TeardownEnvConfigBucketStep extends TeardownStep
{
    protected function resource(): EnvConfigBucket
    {
        return new EnvConfigBucket();
    }
}
