<?php

namespace Codinglabs\Yolo\Concerns;

/**
 * A step that calls Laravel\Prompts\warning() directly writes into the live
 * progress bar's region and the message scrolls off-screen before it can be
 * read; the runner replays recorded warnings after the results table instead.
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

    public function resetWarnings(): void
    {
        $this->recordedWarnings = [];
    }
}
