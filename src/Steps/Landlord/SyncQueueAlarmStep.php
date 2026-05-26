<?php

namespace Codinglabs\Yolo\Steps\Landlord;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\CloudWatch\QueueAlarm;
use Codinglabs\Yolo\Contracts\ExecutesMultitenancyStep;

class SyncQueueAlarmStep implements ExecutesMultitenancyStep
{
    public function __invoke(array $options): StepResult
    {
        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_SYNC;
        }

        (new QueueAlarm(
            alarmName: Helpers::keyedResourceName('landlord-queue-depth-alarm'),
            queueName: Helpers::keyedResourceName('landlord'),
            statistic: 'Sum',
        ))->synchronise();

        return StepResult::SYNCED;
    }
}
