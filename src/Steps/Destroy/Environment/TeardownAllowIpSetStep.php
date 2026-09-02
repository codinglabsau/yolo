<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\WafV2\AllowIpSet;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

class TeardownAllowIpSetStep extends TeardownStep
{
    protected function resource(): AllowIpSet
    {
        return new AllowIpSet();
    }
}
