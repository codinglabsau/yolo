<?php

namespace Codinglabs\Yolo\Steps\Build;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\HasSubSteps;
use Codinglabs\Yolo\Contracts\RunsOnBuild;

class ExecuteBuildStepsStep implements HasSubSteps, RunsOnBuild
{
    public function __invoke(): array
    {
        return Manifest::get('build');
    }
}
