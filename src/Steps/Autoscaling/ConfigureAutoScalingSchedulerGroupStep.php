<?php

namespace Codinglabs\Yolo\Steps\Autoscaling;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Concerns\UsesEc2;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Concerns\UsesAutoscaling;

class ConfigureAutoScalingSchedulerGroupStep implements Step
{
    use UsesAutoscaling;
    use UsesEc2;

    public function __invoke(array $options): StepResult
    {
        if (! Arr::get($options, 'dry-run')) {
            if (! Manifest::get('aws.autoscaling.combine', false)) {
                if (Arr::get($options, 'update')) {
                    $this->update();

                    return StepResult::SYNCED;
                }

                $this->create();

                return StepResult::CREATED;
            }

            // use the web ASG for the scheduler
            Manifest::put('aws.autoscaling.scheduler', Manifest::get('aws.autoscaling.web'));

            return StepResult::SYNCED;
        }

        return Arr::get($options, 'update')
            ? StepResult::WOULD_SYNC
            : StepResult::WOULD_CREATE;
    }

    protected function create(): void
    {
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
                'Tags' => [
                    [
                        'Key' => 'Name',
                        'PropagateAtLaunch' => true,
                        'Value' => Helpers::keyedResourceName(ServerGroup::SCHEDULER, exclusive: false),
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

        Manifest::put('aws.autoscaling.scheduler', $name);
    }

    protected function update(): void
    {
        Aws::autoscaling()->updateAutoScalingGroup([
            'AutoScalingGroupName' => AwsResources::autoScalingGroupScheduler()['AutoScalingGroupName'],
            ...static::autoScalingGroupPayload(),
        ]);
    }
}
