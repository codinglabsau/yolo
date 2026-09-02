<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\DeployersGroup;

class TeardownDeployersGroupStep extends TeardownStep
{
    protected function resource(): DeployersGroup
    {
        return new DeployersGroup();
    }
}
