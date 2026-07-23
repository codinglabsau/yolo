<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\App\Shared;

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\ProvisionsScopedQueues;
use Codinglabs\Yolo\Contracts\ExecutesMultitenancyStep;

/**
 * The queue set for a multi-tenant app on the `shared` strategy — one set at the
 * app's own name (`yolo-{env}-{app}[-tier]`), the same shape a solo app has, drained
 * by a single worker with the tenant carried in the job payload. Wired in place of
 * the per-tenant landlord + tenant queue steps when queue-isolation is `shared`.
 */
class SyncQueueStep implements ExecutesMultitenancyStep
{
    use ProvisionsScopedQueues;

    public function __invoke(array $options): StepResult
    {
        return $this->syncScopedQueues(null, $options);
    }
}
