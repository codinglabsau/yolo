<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Resources\S3\AssetBucket;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

/**
 * Tears down this app's S3 asset bucket.
 */
class TeardownS3AssetBucketStep extends TeardownStep
{
    protected function resource(): AssetBucket
    {
        return new AssetBucket();
    }
}
