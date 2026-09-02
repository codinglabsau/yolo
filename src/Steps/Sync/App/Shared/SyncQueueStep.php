<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\App\Shared;

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\ProvisionsScopedQueues;
use Codinglabs\Yolo\Contracts\ExecutesMultitenancyStep;

/**
 * One queue set at the app's own name, drained by a single worker with the
 * tenant carried in the job payload.
 */
class SyncQueueStep implements ExecutesMultitenancyStep
{
    use ProvisionsScopedQueues;

    public function __invoke(array $options): StepResult
    {
        return $this->syncScopedQueues(null, $options);
    }
}
