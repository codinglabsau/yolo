<?php

namespace Codinglabs\Yolo\Steps\Ami;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Concerns\UsesEc2;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\UsesAutoscaling;

class CreateAutoScalingQueueGroupStep implements Step
{
    use UsesAutoscaling;
    use UsesEc2;

    public function __invoke(): StepResult
    {
        $name = Helpers::keyedResourceName(sprintf('queue-%s', Str::random(8)));

        Aws::autoscaling()->createAutoScalingGroup([
            ...static::autoScalingGroupPayload(),
            ...[
                'AutoScalingGroupName' => $name,
                'MinSize' => 1,
                'MaxSize' => 1,
                'DesiredCapacity' => 1,
                'Tags' => [
                    [
                        'Key' => 'Name',
                        'PropagateAtLaunch' => true,
                        'Value' => 'Queue',
                    ],
                ],
            ],
        ]);

        Aws::autoscaling()->enableMetricsCollection([
            'AutoScalingGroupName' => $name,
            'Granularity' => '1Minute',
        ]);

        Manifest::put('aws.autoscaling.queue', $name);

        return StepResult::SYNCED;
    }
}
