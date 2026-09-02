<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Change;

/**
 * A recorded Change is what keeps a step in the apply pass, so record drift
 * before any dry-run guard or the step is pruned and never self-heals.
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

    public function resetChanges(): void
    {
        $this->recordedChanges = [];
    }
}
