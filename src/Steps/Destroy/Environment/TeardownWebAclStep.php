<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\WafV2\WebAcl;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

class TeardownWebAclStep extends TeardownStep
{
    protected function resource(): WebAcl
    {
        return new WebAcl();
    }
}
