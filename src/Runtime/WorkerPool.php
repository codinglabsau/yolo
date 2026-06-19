<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

/**
 * Reads the FrankenPHP worker-pool size from its Prometheus metrics text — the
 * denominator the burst reporter divides the in-flight peak by to get saturation.
 *
 * Only the *total* is taken from the scrape. `total_workers` is a static gauge: it
 * reads correctly even while the box is pinned (unlike `busy_workers`, whose
 * after-response snapshot under-reports the very pin burst exists to catch — which is
 * why the numerator now comes from {@see InFlightRequests}, counted directly). The pool
 * size is the one thing the runtime can't know without asking FrankenPHP, since the
 * worker count is auto-detected at boot, not a value YOLO sets.
 *
 * Split from the scrape so it can be unit-tested against a real payload.
 */
final class WorkerPool
{
    /**
     * The total worker count summed across every worker-script gauge entry, or null when
     * the gauge is absent (metrics off / classic mode) or zero (caught mid worker-reload),
     * neither of which is a usable denominator.
     */
    public static function total(string $metrics): ?int
    {
        // FrankenPHP emits one gauge line per worker script, optionally
        // Prometheus-labelled (frankenphp_total_workers{worker="/app/..."} 4). Sum across
        // every entry so a multi-worker app reads its whole pool, not just the first line.
        if (! preg_match_all('/^frankenphp_total_workers(?:\{[^}]*\})?\s+([0-9.]+)/m', $metrics, $matches)) {
            return null;
        }

        $total = (int) array_sum(array_map(floatval(...), $matches[1]));

        return $total > 0 ? $total : null;
    }
}
