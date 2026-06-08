<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\App\Landlord;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\CloudWatch\QueueAlarm;
use Codinglabs\Yolo\Contracts\ExecutesMultitenancyStep;

class SyncQueueAlarmStep implements ExecutesMultitenancyStep
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new QueueAlarm(
            alarmName: Helpers::keyedResourceName('landlord-queue-depth-alarm'),
            queueName: Helpers::keyedResourceName('landlord'),
            statistic: 'Sum',
        ), $options);
    }
}
