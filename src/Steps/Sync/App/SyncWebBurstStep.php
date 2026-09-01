<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\WebBurstPolicy;

/**
 * Provisions (or tears down) the web burst scale-out path — a high-res
 * worker-saturation alarm + step-scaling policy ({@see WebBurstPolicy}). Burst is
 * part of web autoscaling, provisioned wherever the scalable target is, so this is
 * wired into sync:app whenever the web task exists: a web tier that drops
 * autoscaling has its policy and self-authored alarm deleted on the next sync rather
 * than orphaned — App Auto Scaling cascades the step policy when the scalable target
 * is deregistered, but the alarm is standalone and must be deleted explicitly.
 *
 * Never gates on the web ECS service existing — on a greenfield PLAN pass nothing
 * exists yet, and a bare SKIPPED there would prune the step from the apply pass
 * (two-pass contract); the apply runs after the service and scalable target the
 * policy attaches to have been created earlier in the same pass.
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
