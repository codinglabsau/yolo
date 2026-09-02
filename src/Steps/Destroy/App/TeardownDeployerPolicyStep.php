<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\DeployerPolicy;

class TeardownDeployerPolicyStep extends TeardownStep
{
    protected function resource(): DeployerPolicy
    {
        return new DeployerPolicy();
    }
}
