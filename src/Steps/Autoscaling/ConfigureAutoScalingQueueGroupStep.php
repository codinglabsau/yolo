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

class ConfigureAutoScalingQueueGroupStep implements Step
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

            // use the web ASG for the queue
            Manifest::put('aws.autoscaling.queue', Manifest::get('aws.autoscaling.web'));

            return StepResult::SYNCED;
        }

        return Arr::get($options, 'update')
            ? StepResult::WOULD_SYNC
            : StepResult::WOULD_CREATE;
    }


    protected function create(): void
    {
        $name = Helpers::keyedResourceName(
            sprintf('%s-%s', ServerGroup::QUEUE->value, Str::random(8)),
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
                        'Value' => Helpers::keyedResourceName(ServerGroup::QUEUE, exclusive: false),
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
    }

    protected function update(): void
    {
        Aws::autoscaling()->updateAutoScalingGroup([
            'AutoScalingGroupName' => AwsResources::autoScalingGroupQueue()['AutoScalingGroupName'],
            ...static::autoScalingGroupPayload(),
        ]);
    }
}
