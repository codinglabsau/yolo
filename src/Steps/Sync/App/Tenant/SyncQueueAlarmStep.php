<?php

namespace Codinglabs\Yolo\Steps\Sync\App\Tenant;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Resources\CloudWatch\QueueAlarm;

class SyncQueueAlarmStep extends TenantStep
{
    public function __invoke(array $options): StepResult
    {
        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_SYNC;
        }

        (new QueueAlarm(
            alarmName: Helpers::keyedResourceName(sprintf('%s-queue-depth-alarm', $this->tenantId())),
            queueName: Helpers::keyedResourceName($this->tenantId()),
        ))->synchronise();

        return StepResult::SYNCED;
    }
}
