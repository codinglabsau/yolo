<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

/**
 * The outcome of one scrape, classified by {@see ScrapeOutcome} and carrying the
 * saturation value only when a real reading was obtained.
 */
final readonly class ScrapeResult
{
    private function __construct(
        public ScrapeOutcome $outcome,
        public ?float $saturation = null,
    ) {}

    public static function reading(float $saturation): self
    {
        return new self(ScrapeOutcome::Reading, $saturation);
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
