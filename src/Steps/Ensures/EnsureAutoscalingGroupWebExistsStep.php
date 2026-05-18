<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;

class EnsureAutoscalingGroupWebExistsStep implements Step
{
    public function __invoke(): StepResult
    {
        if (! Manifest::hasServerGroup(ServerGroup::WEB)) {
            return StepResult::SKIPPED;
        }

        AwsResources::autoScalingGroupWeb();

        return StepResult::SUCCESS;
    }
}
