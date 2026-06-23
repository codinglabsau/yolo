<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\Iam\ObserverRole;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

/**
 * Tears down the observer tier role, after its grant group is gone.
 */
class TeardownObserverRoleStep extends TeardownStep
{
    protected function resource(): ObserverRole
    {
        return new ObserverRole();
    }
}
