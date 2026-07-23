<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\App\Tenant;

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Concerns\ProvisionsScopedQueues;

class SyncQueueStep extends TenantStep
{
    use ProvisionsScopedQueues;

    public function __invoke(array $options): StepResult
    {
        return $this->syncScopedQueues($this->tenantId(), $options);
    }
}
