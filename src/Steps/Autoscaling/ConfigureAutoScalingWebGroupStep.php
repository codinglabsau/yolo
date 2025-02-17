<?php

namespace Codinglabs\Yolo\Steps\Autoscaling;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Concerns\UsesAutoscaling;

class ConfigureAutoScalingWebGroupStep implements Step
{
    use UsesAutoscaling;

    public function __invoke(array $options): StepResult
    {
        if (! Arr::get($options, 'dry-run')) {
            if (Arr::get($options, 'update')) {
                $this->update();
                return StepResult::SYNCED;
            }

            $this->create();
            return StepResult::CREATED;
        }

        return Arr::get($options, 'update')
            ? StepResult::WOULD_SYNC
            : StepResult::WOULD_CREATE;
    }

    protected function create(): void
    {
        $name = Helpers::keyedResourceName(
            sprintf('%s-%s', ServerGroup::WEB->value, Str::random(8)),
            exclusive: false
        );

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
                        'Value' => Helpers::keyedResourceName(ServerGroup::WEB, exclusive: false),
                    ],
                    [
                        'Key' => 'yolo:environment',
                        'Value' => Helpers::app('environment'),
                        'PropagateAtLaunch' => true,
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
    }

    protected function update(): void
    {
        Aws::autoscaling()->updateAutoScalingGroup([
            'AutoScalingGroupName' => AwsResources::autoScalingGroupWeb()['AutoScalingGroupName'],
            ...static::autoScalingGroupPayload(),
        ]);
    }
}
