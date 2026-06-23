<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\EcsExecutionRole;

/**
 * Tears down the shared ECS execution role, after every task (apps + env service
 * nodes) that assumed it is gone.
 */
class TeardownEcsExecutionRoleStep extends TeardownStep
{
    protected function resource(): EcsExecutionRole
    {
        return new EcsExecutionRole();
    }
}
