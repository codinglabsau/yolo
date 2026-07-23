<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Sqs\Queue;

/**
 * Syncs every SQS queue a scope owns — one per declared `queues:` tier, or the
 * single un-suffixed queue when no tiers are declared. Shared by the solo,
 * landlord and per-tenant SyncQueueStep so all three fan out over tiers
 * identically, and over the same names Helpers::queueChain builds the worker's
 * --queue from (queues provisioned == queues drained, never a drift).
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

        // Surface the most significant outcome across the tiers so the plan→apply
        // orchestrator keeps the step for apply whenever any tier still needs work —
        // a WOULD_CREATE/WOULD_SYNC on one tier must not be masked by a clean SYNCED
        // on another (the pending-only prune would then skip the apply).
        foreach ([StepResult::CREATED, StepResult::WOULD_CREATE, StepResult::WOULD_SYNC] as $rank) {
            if (in_array($rank, $results, true)) {
                return $rank;
            }
        }

        return StepResult::SYNCED;
    }
}
