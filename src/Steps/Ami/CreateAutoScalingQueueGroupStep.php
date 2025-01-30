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

class CreateAutoScalingQueueGroupStep implements Step
{
    use UsesAutoscaling;
    use UsesEc2;

    public function __invoke(array $options): StepResult
    {
        if (! Arr::get($options, 'dry-run')) {
            $name = Helpers::keyedResourceName(sprintf('%s-%s', ServerGroup::QUEUE->value, Str::random(8)));

            Aws::autoscaling()->createAutoScalingGroup([
                ...static::autoScalingGroupPayload(),
                ...[
                    'AutoScalingGroupName' => $name,
                    'MinSize' => 1,
                    'MaxSize' => 1,
                    'DesiredCapacity' => 1,
                    // special use case to include 'PropagateAtLaunch' attribute
                    'Tags' => [
                        [
                            'Key' => 'Name',
                            'PropagateAtLaunch' => true,
                            'Value' => ServerGroup::QUEUE->value,
                        ],
                        [
                            'Key' => 'yolo:environment',
                            'Value' => Helpers::app('environment'),
                            'PropagateAtLaunch' => true,
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

        return StepResult::WOULD_CREATE;
    }
}
