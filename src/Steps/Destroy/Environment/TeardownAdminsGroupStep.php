<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\Iam\AdminsGroup;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

/**
 * Tears down the env admins grant group (removes members + its assume-role
 * policy, then deletes the group).
 */
class TeardownAdminsGroupStep extends TeardownStep
{
    protected function resource(): AdminsGroup
    {
        return new AdminsGroup();
    }
}
