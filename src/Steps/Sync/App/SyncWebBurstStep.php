<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\WebBurstPolicy;

/**
 * Wired into sync:app whenever the web task exists, so a web tier that drops
 * autoscaling has its policy and alarm deleted rather than orphaned — App Auto
 * Scaling cascades the step policy when the scalable target is deregistered,
 * but the alarm is standalone and must be deleted explicitly. Never gates on
 * the ECS service existing: a bare SKIPPED on the greenfield plan pass would
 * prune the step from apply.
 */
class SyncWebBurstStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $dryRun = (bool) Arr::get($options, 'dry-run');
        $burst = new WebBurstPolicy();

        if (! Manifest::isAutoscaling()) {
            $changes = $burst->teardown(apply: ! $dryRun);

            $this->recordChanges($changes);

            if ($changes === []) {
                return StepResult::SKIPPED;
            }

            return $dryRun ? StepResult::WOULD_DELETE : StepResult::DELETED;
        }

        $existed = $burst->exists();
        $changes = $burst->synchronise(apply: ! $dryRun);

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
