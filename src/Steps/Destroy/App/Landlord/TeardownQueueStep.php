<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App\Landlord;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\TearsDownScopedQueues;
use Codinglabs\Yolo\Contracts\ExecutesMultitenancyStep;
use Codinglabs\Yolo\Steps\Sync\App\Landlord\SyncQueueStep;

/**
 * Twin of {@see SyncQueueStep}, gated on the same predicate so a `shared` app
 * never reports a landlord queue it never had.
 */
class TeardownQueueStep implements ExecutesMultitenancyStep, Step
{
    use TearsDownScopedQueues;

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::fansQueuesPerTenant()) {
            return StepResult::SKIPPED;
        }

        return $this->tearDownScopedQueues('landlord', $options);
    }
}
