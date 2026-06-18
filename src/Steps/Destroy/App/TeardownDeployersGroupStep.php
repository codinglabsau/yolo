<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\DeployersGroup;

/**
 * Tears down this app's deployers IAM group.
 */
class TeardownDeployersGroupStep extends TeardownStep
{
    protected function resource(): DeployersGroup
    {
        return new DeployersGroup();
    }
}
