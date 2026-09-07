<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

/**
 * Exactly one gauge family is present per reading — `totalWorkers` / `busyWorkers` on
 * an Octane tier, `busyThreads` / `queueDepth` on a classic one — so `totalWorkers`
 * doubles as the mode discriminator. `gauges` carries the raw diagnostic set
 * ({@see Gauges::diagnostics()}) for the reporter's per-window log line; it never feeds
 * the formula.
 */
final readonly class ScrapeResult
{
    private function __construct(
        public ScrapeOutcome $outcome,
        public ?int $totalWorkers = null,
        public int $busyWorkers = 0,
        public int $busyThreads = 0,
        public int $queueDepth = 0,
        /** @var array<string, int|null> */
        public array $gauges = [],
    ) {}

    /**
     * A worker-mode reading: the resident pool size, and the requests dispatched to it
     * (queued ones included, so busy can exceed total).
     *
     * @param  array<string, int|null>  $gauges
     */
    public static function workers(int $totalWorkers, int $busyWorkers, array $gauges = []): self
    {
        return new self(ScrapeOutcome::Reading, $totalWorkers, $busyWorkers, gauges: $gauges);
    }

    /**
     * A classic-mode reading: threads busy right now, and requests waiting for one.
     *
     * @param  array<string, int|null>  $gauges
     */
    public static function threads(int $busyThreads, int $queueDepth, array $gauges = []): self
    {
        return new self(ScrapeOutcome::Reading, busyThreads: $busyThreads, queueDepth: $queueDepth, gauges: $gauges);
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
