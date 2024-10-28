<?php

namespace Codinglabs\Yolo\Steps\Ensures;

use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

class EnsureEc2SecurityGroupExistsStep implements Step
{
    public function __invoke(): StepResult
    {
        AwsResources::loadBalancerSecurityGroup();

        return StepResult::SUCCESS;
    }
}
