<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\S3\S3LogsBucket;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

class TeardownEnvLogsBucketStep extends TeardownStep
{
    protected function resource(): S3LogsBucket
    {
        return new S3LogsBucket();
    }
}
