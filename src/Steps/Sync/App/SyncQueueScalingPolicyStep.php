<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\QueueBacklogPolicy;

/**
 * Never gates on the queue service existing — a bare SKIPPED on the greenfield
 * plan pass would prune the step from apply.
 */
class SyncQueueScalingPolicyStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        // The scalable target's deregistration cascades this policy away.
        if (! Manifest::autoscales(ServerGroup::QUEUE)) {
            return StepResult::SKIPPED;
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');
        $policy = new QueueBacklogPolicy();
        $existed = $policy->exists();

        $changes = $policy->synchronise(apply: ! $dryRun);

        $this->recordChanges($changes);

        if (! $existed) {
            return $dryRun ? StepResult::WOULD_CREATE : StepResult::CREATED;
        }

        if ($changes !== []) {
            return $dryRun ? StepResult::WOULD_SYNC : StepResult::SYNCED;
        }

        return StepResult::SYNCED;
    }
}
