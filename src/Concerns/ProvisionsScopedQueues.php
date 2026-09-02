<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Sqs\Queue;

/**
 * Fans out over the same names Helpers::queueChain builds the worker's --queue
 * from, so queues provisioned == queues drained.
 */
trait ProvisionsScopedQueues
{
    use SynchronisesResource;

    protected function syncScopedQueues(?string $scope, array $options): StepResult
    {
        $results = array_map(
            fn (string $name): StepResult => $this->syncResource(new Queue($name), $options),
            Helpers::queueNames($scope),
        );

        // A WOULD_* on one tier must not be masked by a clean SYNCED on another, or
        // the pending-only prune skips the apply.
        foreach ([StepResult::CREATED, StepResult::WOULD_CREATE, StepResult::WOULD_SYNC] as $rank) {
            if (in_array($rank, $results, true)) {
                return $rank;
            }
        }

        return StepResult::SYNCED;
    }
}
