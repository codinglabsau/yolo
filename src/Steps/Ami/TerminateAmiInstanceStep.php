<?php

namespace Codinglabs\Yolo\Steps\Ami;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Concerns\UsesEc2;
use Codinglabs\Yolo\Enums\StepResult;

class TerminateAmiInstanceStep implements Step
{
    use UsesEc2;

    public function __invoke(): StepResult
    {
        Aws::ec2()->terminateInstances([
            'InstanceIds' => [
                Helpers::app('amiInstanceId'),
            ],
        ]);

        return StepResult::SUCCESS;
    }
}
