<?php

namespace Codinglabs\Yolo\Steps;

use Codinglabs\Yolo\Concerns\ExecutesCommands;
use Codinglabs\Yolo\Contracts\RunsOnAwsScheduler;
use Codinglabs\Yolo\Contracts\ExecutesCommandStep;

class ExecuteCommandOnAwsSchedulerStep implements ExecutesCommandStep, RunsOnAwsScheduler
{
    use ExecutesCommands;
}
