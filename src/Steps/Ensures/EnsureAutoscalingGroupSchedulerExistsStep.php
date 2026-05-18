<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;

class EnsureAutoscalingGroupSchedulerExistsStep implements Step
{
    public function __invoke(): StepResult
    {
        if (! Manifest::hasServerGroup(ServerGroup::SCHEDULER)) {
            return StepResult::SKIPPED;
        }

        AwsResources::autoScalingGroupScheduler();

        return StepResult::SUCCESS;
    }
}
