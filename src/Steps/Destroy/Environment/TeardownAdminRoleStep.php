<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\Iam\AdminRole;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

/**
 * Tears down the admin tier role, after its grant group is gone.
 */
class TeardownAdminRoleStep extends TeardownStep
{
    protected function resource(): AdminRole
    {
        return new AdminRole();
    }
}
