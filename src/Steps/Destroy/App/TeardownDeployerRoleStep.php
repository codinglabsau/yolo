<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Resources\Iam\DeployerRole;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

/**
 * Tears down this app's deployer IAM role.
 */
class TeardownDeployerRoleStep extends TeardownStep
{
    protected function resource(): DeployerRole
    {
        return new DeployerRole();
    }
}
