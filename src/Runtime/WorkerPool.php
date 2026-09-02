<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

/**
 * Only `total_workers` is taken from the scrape — a static gauge that reads
 * correctly even while the box is pinned (unlike `busy_workers`, see
 * {@see InFlightRequests}). It's the one number the runtime can't otherwise
 * know: FrankenPHP auto-detects the worker count at boot, YOLO doesn't set it.
 */
final class WorkerPool
{
    /**
     * Null when the gauge is absent (metrics off / classic mode) or zero (caught
     * mid worker-reload) — neither is a usable denominator.
     */
    public static function total(string $metrics): ?int
    {
        // One gauge line per worker script, optionally Prometheus-labelled
        // (frankenphp_total_workers{worker="/app/..."} 4) — sum across every entry.
        if (! preg_match_all('/^frankenphp_total_workers(?:\{[^}]*\})?\s+([0-9.]+)/m', $metrics, $matches)) {
            return null;
        }

        $total = (int) array_sum(array_map(floatval(...), $matches[1]));

        return $total > 0 ? $total : null;
    }
}
