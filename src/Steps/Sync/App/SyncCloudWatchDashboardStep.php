<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\CloudWatch\Dashboard;

/**
 * Provisions the per-app CloudWatch dashboard. Runs last in sync:app so every
 * resource it visualises already exists on the apply pass. Dry-run honest: it
 * diffs the desired body against the live dashboard and reports WOULD_CREATE /
 * WOULD_SYNC / SYNCED accordingly, rather than blind-stamping like the queue
 * alarm step.
 */
class SyncCloudWatchDashboardStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        $dryRun = (bool) Arr::get($options, 'dry-run');
        $dashboard = new Dashboard();

        $existed = $dashboard->exists();

        $changes = $dashboard->synchronise(apply: ! $dryRun);

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
