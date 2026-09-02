<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\AppObserverRole;

class TeardownAppObserverRoleStep extends TeardownStep
{
    protected function resource(): AppObserverRole
    {
        return new AppObserverRole();
    }
}
