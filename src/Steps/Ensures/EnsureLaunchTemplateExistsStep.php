<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

class EnsureLaunchTemplateExistsStep implements Step
{
    public function __invoke(): StepResult
    {
        AwsResources::launchTemplate();

        return StepResult::SUCCESS;
    }
}
