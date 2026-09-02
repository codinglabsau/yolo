<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\AppObserversGroup;

class TeardownAppObserversGroupStep extends TeardownStep
{
    protected function resource(): AppObserversGroup
    {
        return new AppObserversGroup();
    }
}
