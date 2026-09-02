<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\S3\S3ConfigBucket;

class TeardownS3ConfigBucketStep extends TeardownStep
{
    protected function resource(): S3ConfigBucket
    {
        return new S3ConfigBucket();
    }
}
