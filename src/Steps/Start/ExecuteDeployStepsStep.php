<?php

namespace Codinglabs\Yolo\Steps\Start;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\HasSubSteps;
use Codinglabs\Yolo\Contracts\RunsOnAwsScheduler;

class ExecuteDeployStepsStep implements HasSubSteps, RunsOnAwsScheduler
{
    public function __invoke(): array
    {
        return Manifest::get('deploy');
    }
}
