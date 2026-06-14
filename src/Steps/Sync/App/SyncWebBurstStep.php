<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\WebBurstPolicy;

/**
 * Provisions (or tears down) the opt-in web burst scale-out path — a high-res
 * worker-saturation alarm + step-scaling policy ({@see WebBurstPolicy}) — gated on
 * `tasks.web.autoscaling.burst`. Wired into sync:app whenever the web task exists,
 * not only when burst is on, so switching it off (or removing autoscaling) deletes
 * the policy and its self-authored alarm on the next sync rather than orphaning
 * them — App Auto Scaling cascades the step policy when the scalable target is
 * deregistered, but the alarm is standalone and must be deleted explicitly.
 *
 * Skips on a greenfield first sync when the web ECS service doesn't exist yet (the
 * policy attaches to its scalable target).
 */
class SyncWebBurstStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        if (! (new EcsService(ServerGroup::WEB))->exists()) {
            return StepResult::SKIPPED;
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');
        $burst = new WebBurstPolicy();

        if (! Manifest::webBurstEnabled()) {
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

        return StepResult::SYNCED;
    }
}
