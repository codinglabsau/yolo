<?php

namespace Codinglabs\Yolo\Steps\Ami;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Concerns\UsesEc2;
use Codinglabs\Yolo\Enums\StepResult;

class StopAmiInstanceStep implements Step
{
    use UsesEc2;

    public function __invoke(): StepResult
    {
        Aws::ec2()->stopInstances([
            'InstanceIds' => [Helpers::app('amiInstanceId')],
        ]);

        while (true) {
            // wait for instance to stop
            if (static::ec2ByName('AMI', states: ['stopped'], throws: false)) {
                break;
            }

            sleep(3);
        }

        return StepResult::SUCCESS;
    }
}
