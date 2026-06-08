<?php

namespace Codinglabs\Yolo\Steps\Sync\App\Solo;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\CloudWatch\QueueAlarm;

class SyncQueueAlarmStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new QueueAlarm(
            alarmName: Helpers::keyedResourceName('queue-depth-alarm'),
            queueName: Helpers::keyedResourceName(),
        ), $options);
    }
}
