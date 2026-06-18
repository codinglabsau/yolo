<?php

namespace Codinglabs\Yolo\Concerns;

/**
 * Lets a step accumulate free-text warnings for the operator without printing
 * them mid-run. A step that calls Laravel\Prompts\warning() directly writes into
 * the live progress bar's region — desyncing its in-place repaint (the box
 * doubles) and scrolling the message off-screen before it can be read. Instead
 * the step records its warnings here and the stepped-command runner replays them
 * in one block after the progress bar has finished and the results table is
 * drawn. The runner reads them back via recordedWarnings() after invoking the
 * step; resetWarnings() clears them between the plan and apply passes, exactly
 * like RecordsChanges.
 */
trait RecordsWarnings
{
    /** @var array<int, string> */
    protected array $recordedWarnings = [];

    /**
     * @return array<int, string>
     */
    public function recordedWarnings(): array
    {
        return $this->recordedWarnings;
    }

    protected function recordWarning(string $warning): void
    {
        $this->recordedWarnings[] = $warning;
    }

    /**
     * Drop everything recorded so the next invocation starts clean — used by
     * `runScopes` between the plan and apply passes so the apply pass doesn't
     * carry forward the plan pass's warnings on the same step instance.
     */
    public function resetWarnings(): void
    {
        $this->recordedWarnings = [];
    }
}
