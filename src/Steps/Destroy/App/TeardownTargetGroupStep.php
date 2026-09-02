<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\ElbV2\TargetGroup;

class TeardownTargetGroupStep extends TeardownStep
{
    protected function resource(): TargetGroup
    {
        return new TargetGroup();
    }
}
