<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

class EnsureAutoscalingGroupQueueExistsStep implements Step
{
    public function __invoke(): StepResult
    {
        AwsResources::autoScalingGroupQueue();

        return StepResult::SUCCESS;
    }
}
