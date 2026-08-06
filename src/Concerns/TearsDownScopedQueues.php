<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Sqs\Queue;

/**
 * Tears down every SQS queue a scope owns — the mirror of
 * {@see ProvisionsScopedQueues}, reading the same Helpers::queueNames() so a
 * teardown removes exactly the set sync created. Shared by the single-scope,
 * landlord and per-tenant teardown steps.
 *
 * Reading the scoped names is what makes a tiered app tear down cleanly: a
 * `queues: [high, default]` block provisions `…-high` alongside the base queue, so
 * naming only the base name would strand the tier queues in the account.
 */
trait TearsDownScopedQueues
{
    use SynchronisesResource;

    protected function tearDownScopedQueues(?string $scope, array $options): StepResult
    {
        $results = array_map(
            fn (string $name): StepResult => $this->teardownResource(new Queue($name), $options),
            Helpers::queueNames($scope),
        );

        // Surface the most significant outcome across the tiers, so one tier already
        // gone can't mask another still standing and prune the step out of apply.
        foreach ([StepResult::DELETED, StepResult::WOULD_DELETE] as $rank) {
            if (in_array($rank, $results, true)) {
                return $rank;
            }
        }

        return StepResult::SKIPPED;
    }
}
