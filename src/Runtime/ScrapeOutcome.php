<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

/**
 * How one scrape of FrankenPHP's metrics endpoint turned out. The three cases mean
 * very different things to the reporter:
 *
 *  - Reading — gauges present. In worker mode that's a usable total_workers count
 *    (the saturation denominator; the numerator is counted directly via
 *    {@see InFlightRequests}); in classic mode it's the thread gauges — busy_threads
 *    and queue_depth — which the reporter reads against the thread ceiling YOLO pinned.
 *  - Failure — no usable response (connection refused OR timed out). From inside a
 *    request that already holds a CPU slice this is strong evidence the box is
 *    pinned — but only once the reporter has been "primed" by a prior success (see
 *    {@see WorkerSaturationReporter}), which is what separates "in trouble" from a
 *    boot race / metrics misconfig far more robustly than parsing cURL errnos.
 *  - Absent — a 200 with no frankenphp_* gauges at all: metrics off. Config, never
 *    load — never a panic.
 */
enum ScrapeOutcome
{
    case Reading;
    case Failure;
    case Absent;
}
