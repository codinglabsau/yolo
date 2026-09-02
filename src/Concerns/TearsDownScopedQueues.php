<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Sqs\Queue;

/**
 * Mirror of {@see ProvisionsScopedQueues}, reading the same Helpers::queueNames()
 * so teardown removes exactly the set sync created — naming only the base queue
 * would strand the tier queues.
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

        // One tier already gone must not mask another still standing and prune the step from apply.
        foreach ([StepResult::DELETED, StepResult::WOULD_DELETE] as $rank) {
            if (in_array($rank, $results, true)) {
                return $rank;
            }
        }

        return StepResult::SKIPPED;
    }
}
