<?php

namespace Codinglabs\Yolo\Steps\Sync\App\Tenant;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\CloudWatch\QueueAlarm;

class SyncQueueAlarmStep extends TenantStep
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new QueueAlarm(
            alarmName: Helpers::keyedResourceName(sprintf('%s-queue-depth-alarm', $this->tenantId())),
            queueName: Helpers::keyedResourceName($this->tenantId()),
        ), $options);
    }
}
