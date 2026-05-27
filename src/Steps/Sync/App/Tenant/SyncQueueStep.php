<?php

namespace Codinglabs\Yolo\Steps\Sync\App\Tenant;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Resources\Sqs\Queue;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncQueueStep extends TenantStep
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new Queue(Helpers::keyedResourceName($this->tenantId())), $options);
    }
}
