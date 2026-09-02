<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\WafV2\BlockIpSet;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

class TeardownBlockIpSetStep extends TeardownStep
{
    protected function resource(): BlockIpSet
    {
        return new BlockIpSet();
    }
}
