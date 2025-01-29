<?php

namespace Codinglabs\Yolo\Steps\Ami;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Concerns\UsesEc2;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Concerns\UsesAutoscaling;

class CreateAutoScalingSchedulerGroupStep implements Step
{
    use UsesAutoscaling;
    use UsesEc2;

    public function __invoke(array $options): StepResult
    {
        if (! Arr::get($options, 'dry-run')) {
            $name = Helpers::keyedResourceName(sprintf('scheduler-%s', Str::random(8)));

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
                            'Value' => ServerGroup::SCHEDULER->value,
                        ],
                    ],
                ],
            ]);

            Manifest::put('aws.autoscaling.scheduler', $name);

            return StepResult::SYNCED;
        }

        return StepResult::WOULD_CREATE;
    }
}
