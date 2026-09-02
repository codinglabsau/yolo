<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

/**
 * Failure (refused or timed out) from a request that holds a CPU slice is
 * evidence the box is pinned — acted on only once primed, which separates "in
 * trouble" from a boot race far more robustly than parsing cURL errnos. Absent
 * (a 200 with no gauges) is metrics off / classic mode: config, never load.
 */
enum ScrapeOutcome
{
    case Reading;
    case Failure;
    case Absent;
}
