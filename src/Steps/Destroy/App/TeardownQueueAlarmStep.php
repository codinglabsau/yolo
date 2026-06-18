<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\CloudWatch\QueueAlarm;

/**
 * Tears down this app's SQS queue-depth CloudWatch alarm.
 */
class TeardownQueueAlarmStep extends TeardownStep
{
    protected function resource(): QueueAlarm
    {
        return new QueueAlarm(alarmName: Helpers::keyedResourceName('queue-depth-alarm'), queueName: Helpers::keyedResourceName());
    }
}
