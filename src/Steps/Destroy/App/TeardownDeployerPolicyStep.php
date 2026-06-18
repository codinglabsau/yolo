<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\DeployerPolicy;

/**
 * Tears down this app's deployer IAM policy.
 */
class TeardownDeployerPolicyStep extends TeardownStep
{
    protected function resource(): DeployerPolicy
    {
        return new DeployerPolicy();
    }
}
