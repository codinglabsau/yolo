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
 * The 0→1 bootstrap only matters when the queue actually scales to zero — a
 * queue with a standing floor never sits at zero.
 */
class SyncQueueScaleToZeroAlarmStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        // Never gated on the queue service existing — a bare SKIPPED on the
        // greenfield plan pass would prune the step from apply.
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
