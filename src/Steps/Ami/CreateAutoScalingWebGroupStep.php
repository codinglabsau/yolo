<?php

namespace Codinglabs\Yolo\Steps\Ami;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\UsesAutoscaling;

class CreateAutoScalingWebGroupStep implements Step
{
    use UsesAutoscaling;

    public function __invoke(array $options): StepResult
    {
        if (! Arr::get($options, 'dry-run')) {
            $name = Helpers::keyedResourceName(sprintf('web-%s', Str::random(8)));

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
                            'Value' => 'web',
                        ],
                    ],
                ],
            ]);

            Aws::autoscaling()->putScalingPolicy([
                'AutoScalingGroupName' => $name,
                'PolicyName' => "$name-up",
                'AdjustmentType' => 'ChangeInCapacity',
                'Cooldown' => 60,
                'ScalingAdjustment' => 2,
            ]);

            Aws::autoscaling()->putScalingPolicy([
                'AutoScalingGroupName' => $name,
                'PolicyName' => "$name-down",
                'AdjustmentType' => 'ChangeInCapacity',
                'Cooldown' => 300,
                'ScalingAdjustment' => -1,
            ]);

            Aws::autoscaling()->enableMetricsCollection([
                'AutoScalingGroupName' => $name,
                'Granularity' => '1Minute',
            ]);

            Manifest::put('aws.autoscaling.web', $name);

            return StepResult::SYNCED;
        }

        return StepResult::WOULD_CREATE;
    }
}
