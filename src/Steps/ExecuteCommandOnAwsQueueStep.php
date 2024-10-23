<?php

namespace Codinglabs\Yolo\Steps;

use Codinglabs\Yolo\Contracts\RunsOnAwsQueue;
use Codinglabs\Yolo\Concerns\ExecutesCommands;
use Codinglabs\Yolo\Contracts\ExecutesCommandStep;

class ExecuteCommandOnAwsQueueStep implements ExecutesCommandStep, RunsOnAwsQueue
{
    use ExecutesCommands;
}
