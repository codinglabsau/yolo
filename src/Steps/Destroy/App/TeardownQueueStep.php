<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\TearsDownScopedQueues;
use Codinglabs\Yolo\Steps\Sync\App\Solo\SyncQueueStep;

/**
 * Twin of {@see SyncQueueStep}. Gated on fansQueuesPerTenant() rather than an
 * ExecutesSoloStep / ExecutesMultitenancyStep contract: those split on whether
 * tenants exist, but a `shared` app has tenants *and* this queue set — a
 * solo-contracted step would skip it and strand the queues.
 */
class TeardownQueueStep implements Step
{
    use TearsDownScopedQueues;

    public function __invoke(array $options): StepResult
    {
        if (Manifest::fansQueuesPerTenant()) {
            return StepResult::SKIPPED;
        }

        return $this->tearDownScopedQueues(null, $options);
    }
}
