<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

/**
 * Reading is the gauges: worker mode's total_workers (the saturation denominator), or
 * classic mode's busy_threads / queue_depth. Failure (refused or timed out) from a
 * request that holds a CPU slice is evidence the box is pinned — acted on only once
 * primed, which separates "in trouble" from a boot race far more robustly than parsing
 * cURL errnos. Absent (a 200 with no gauges) is metrics off: config, never load.
 */
enum ScrapeOutcome
{
    case Reading;
    case Failure;
    case Absent;
}
