<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\ScalableTarget;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\QueueScaleToZeroBootstrap;

/**
 * Provisions the queue's 0→1 bootstrap (a step-scaling policy + a "queue has
 * messages" alarm) — but only when the queue actually scales to zero
 * (`tasks.queue.autoscaling.min: 0`). A queue with a standing floor never sits at zero, so it
 * needs no bootstrap and this step skips. Wired into sync:app only when
 * tasks.queue is set.
 */
class SyncQueueScaleToZeroAlarmStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        // Only meaningful for a scale-to-zero queue: the queue must autoscale
        // (a fixed single task never idles to zero) and its floor must be 0. Never
        // gated on the queue service existing — a bare SKIPPED on the greenfield
        // plan pass would prune the step from apply (two-pass contract); the apply
        // runs after the service and scalable target it attaches to are created.
        if (! Manifest::autoscales(ServerGroup::QUEUE)
            || (new ScalableTarget(ServerGroup::QUEUE))->min() !== 0) {
            return StepResult::SKIPPED;
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');
        $bootstrap = new QueueScaleToZeroBootstrap();
        $existed = $bootstrap->exists();

        $changes = $bootstrap->synchronise(apply: ! $dryRun);

        $this->recordChanges($changes);

        if (! $existed) {
            return $dryRun ? StepResult::WOULD_CREATE : StepResult::CREATED;
        }

        return StepResult::SYNCED;
    }
}
