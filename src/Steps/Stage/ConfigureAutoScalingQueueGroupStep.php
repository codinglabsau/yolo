<?php

namespace Codinglabs\Yolo\Steps\Stage;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Concerns\ConfiguresAutoScalingGroups;

class ConfigureAutoScalingQueueGroupStep implements Step
{
    use ConfiguresAutoScalingGroups;

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::hasServerGroup(ServerGroup::QUEUE)) {
            return StepResult::SKIPPED;
        }

        if (! Arr::get($options, 'dry-run')) {
            if (Arr::get($options, 'update')) {
                static::updateAutoScalingGroup(ServerGroup::QUEUE);

                return StepResult::SYNCED;
            }

            Manifest::put('aws.autoscaling.queue', static::createAutoScalingGroup(ServerGroup::QUEUE));

            return StepResult::CREATED;
        }

        return Arr::get($options, 'update')
            ? StepResult::WOULD_SYNC
            : StepResult::WOULD_CREATE;
    }
}
