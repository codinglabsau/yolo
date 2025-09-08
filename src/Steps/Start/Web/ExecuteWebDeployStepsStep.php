<?php

namespace Codinglabs\Yolo\Steps\Start\Web;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\HasSubSteps;
use Codinglabs\Yolo\Contracts\RunsOnAwsWeb;

class ExecuteWebDeployStepsStep implements HasSubSteps, RunsOnAwsWeb
{
    public function __invoke(): array
    {
        return Manifest::get('deploy-web', []);
    }
}
