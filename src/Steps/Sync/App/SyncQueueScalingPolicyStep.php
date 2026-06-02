<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\QueueBacklogPolicy;

/**
 * Reconciles the queue service's backlog-per-task target-tracking policy onto its
 * scalable target — the policy that scales the queue 1→N on
 * messages-per-running-task. Wired into sync:app only when tasks.queue is set.
 *
 * Skips on a greenfield first sync when the queue service doesn't exist yet (the
 * scalable target lands first, in the same sync pass, just before this).
 */
class SyncQueueScalingPolicyStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        if (! (new EcsService(ServerGroup::QUEUE))->exists()) {
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
