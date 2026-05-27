<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Change;

/**
 * Lets a step accumulate the attribute-level changes it reconciled (or, under
 * --dry-run, would reconcile) so the stepped-command runner can surface them in
 * its Changes report. The runner reads them back via changes() after invoking
 * the step. Steps backed by a Resource get this for free through
 * SynchronisesResource; steps with bespoke reconcile logic use it directly.
 */
trait RecordsChanges
{
    /** @var array<int, Change> */
    protected array $recordedChanges = [];

    /**
     * @return array<int, Change>
     */
    public function changes(): array
    {
        return $this->recordedChanges;
    }

    protected function recordChange(Change $change): void
    {
        $this->recordedChanges[] = $change;
    }

    /**
     * @param  array<int, Change>  $changes
     */
    protected function recordChanges(array $changes): void
    {
        foreach ($changes as $change) {
            $this->recordedChanges[] = $change;
        }
    }
}
