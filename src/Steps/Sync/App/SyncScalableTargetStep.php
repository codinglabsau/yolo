<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\ScalableTarget;

/**
 * Registers the web service's Application Auto Scaling scalable target (the
 * min/max desired-count bounds the scaling policies move within). Only wired into
 * sync:app when a tasks.web.autoscaling block is present, so an app with no
 * autoscaling config keeps today's fixed single task.
 *
 * Dry-run honest via ScalableTarget::synchronise. Skips on a greenfield first
 * sync when the ECS service doesn't exist yet — there's nothing to scale until it
 * does, and the next sync registers the target once the service is live.
 */
class SyncScalableTargetStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        if (! (new EcsService())->exists()) {
            return StepResult::SKIPPED;
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');
        $target = new ScalableTarget();

        $existed = $target->exists();
        $changes = $target->synchronise(apply: ! $dryRun);

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
