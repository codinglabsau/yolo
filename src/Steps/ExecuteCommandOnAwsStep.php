<?php

namespace Codinglabs\Yolo\Steps;

use Codinglabs\Yolo\Contracts\RunsOnAws;
use Codinglabs\Yolo\Concerns\ExecutesCommands;
use Codinglabs\Yolo\Contracts\ExecutesCommandStep;

class ExecuteCommandOnAwsStep implements ExecutesCommandStep, RunsOnAws
{
    use ExecutesCommands;
}
