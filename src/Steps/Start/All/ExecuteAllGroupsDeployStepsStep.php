<?php

namespace Codinglabs\Yolo\Steps\Start\All;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\RunsOnAws;
use Codinglabs\Yolo\Contracts\HasSubSteps;

class ExecuteAllGroupsDeployStepsStep implements HasSubSteps, RunsOnAws
{
    public function __invoke(): array
    {
        return Manifest::get('deploy-all');
    }
}
