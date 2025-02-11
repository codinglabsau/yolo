<?php

namespace Codinglabs\Yolo\Steps\Image;

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
            if (! Manifest::get('aws.autoscaling.combine', false)) {
                $name = Helpers::keyedResourceName(
                    sprintf('%s-%s', ServerGroup::SCHEDULER->value, Str::random(8)),
                    exclusive: false
                );

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
                                'Value' => ServerGroup::SCHEDULER->value,
                            ],
                            [
                                'Key' => 'yolo:environment',
                                'Value' => Helpers::app('environment'),
                                'PropagateAtLaunch' => true,
                            ],
                        ],
                    ],
                ]);

                Manifest::put('aws.autoscaling.scheduler', $name);
            } else {
                // use the web ASG for the scheduler
                Manifest::put('aws.autoscaling.scheduler', Manifest::get('aws.autoscaling.web'));
            }

            return StepResult::SYNCED;
        }

        return StepResult::WOULD_CREATE;
    }
}
