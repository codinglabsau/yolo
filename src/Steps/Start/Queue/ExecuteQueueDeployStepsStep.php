<?php

namespace Codinglabs\Yolo\Steps\Start\Queue;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\HasSubSteps;
use Codinglabs\Yolo\Contracts\RunsOnAwsQueue;

class ExecuteQueueDeployStepsStep implements HasSubSteps, RunsOnAwsQueue
{
    public function __invoke(): array
    {
        return Manifest::get('deploy-queue', []);
    }
}
