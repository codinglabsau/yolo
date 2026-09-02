<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

/**
 * The outcome of one scrape, classified by {@see ScrapeOutcome} and carrying the gauges
 * a real reading obtained. Exactly one gauge family is present per reading: the
 * worker-pool size on an Octane tier (`totalWorkers`, the saturation denominator), or
 * the thread gauges on a classic tier (`busyThreads` / `queueDepth`, read against the
 * thread ceiling YOLO pinned) — so `totalWorkers` doubles as the mode discriminator.
 */
final readonly class ScrapeResult
{
    private function __construct(
        public ScrapeOutcome $outcome,
        public ?int $totalWorkers = null,
        public int $busyThreads = 0,
        public int $queueDepth = 0,
    ) {}

    /** A worker-mode reading: the resident pool size. */
    public static function workers(int $totalWorkers): self
    {
        return new self(ScrapeOutcome::Reading, $totalWorkers);
    }

    /** A classic-mode reading: threads busy right now, and requests waiting for one. */
    public static function threads(int $busyThreads, int $queueDepth): self
    {
        return new self(ScrapeOutcome::Reading, busyThreads: $busyThreads, queueDepth: $queueDepth);
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
