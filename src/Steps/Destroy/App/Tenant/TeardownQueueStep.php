<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App\Tenant;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Concerns\TearsDownScopedQueues;
use Codinglabs\Yolo\Steps\Sync\App\Tenant\SyncQueueStep;

/**
 * Twin of {@see SyncQueueStep}, gated on the same predicate so a `shared` app
 * never reports per-tenant queues it never provisioned.
 */
class TeardownQueueStep extends TenantStep
{
    use TearsDownScopedQueues;

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::fansQueuesPerTenant()) {
            return StepResult::SKIPPED;
        }

        return $this->tearDownScopedQueues($this->tenantId(), $options);
    }
}
