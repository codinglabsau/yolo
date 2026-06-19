<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

/**
 * The outcome of one scrape, classified by {@see ScrapeOutcome} and carrying the
 * worker-pool size — the saturation denominator — only when a real reading was obtained.
 */
final readonly class ScrapeResult
{
    private function __construct(
        public ScrapeOutcome $outcome,
        public ?int $totalWorkers = null,
    ) {}

    public static function reading(int $totalWorkers): self
    {
        return new self(ScrapeOutcome::Reading, $totalWorkers);
    }

    public static function failure(): self
    {
        return new self(ScrapeOutcome::Failure);
    }

    public static function absent(): self
    {
        return new self(ScrapeOutcome::Absent);
    }
}
