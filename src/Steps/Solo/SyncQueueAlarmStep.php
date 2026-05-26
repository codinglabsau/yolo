<?php

namespace Codinglabs\Yolo\Steps\Solo;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\CloudWatch\QueueAlarm;

class SyncQueueAlarmStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_SYNC;
        }

        (new QueueAlarm(
            alarmName: Helpers::keyedResourceName('queue-depth-alarm'),
            queueName: Helpers::keyedResourceName(),
        ))->synchronise();

        return StepResult::SYNCED;
    }
}
