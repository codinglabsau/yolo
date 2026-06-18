<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Resources\Iam\EcsTaskRole;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

/**
 * Tears down this app's ECS task IAM role.
 */
class TeardownEcsTaskRoleStep extends TeardownStep
{
    protected function resource(): EcsTaskRole
    {
        return new EcsTaskRole();
    }
}
