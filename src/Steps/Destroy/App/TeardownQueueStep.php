<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\TearsDownScopedQueues;
use Codinglabs\Yolo\Steps\Sync\App\Solo\SyncQueueStep;

/**
 * Tears down the queue set at the app's own name — the single scope a solo app, a
 * landlord-only `multitenancy` block, and a `shared` multi-tenant app all
 * provision. The teardown twin of {@see SyncQueueStep} and
 * {@see \Codinglabs\Yolo\Steps\Sync\App\Shared\SyncQueueStep}, which are two
 * spellings of that same set.
 *
 * Gated on fansQueuesPerTenant() rather than carrying an ExecutesSoloStep /
 * ExecutesMultitenancyStep contract, because neither describes it: those split on
 * whether tenants exist, while this set's existence turns on whether queues fan
 * out. A `shared` app has tenants *and* this set — a solo-contracted step would
 * skip it and strand the queues.
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
