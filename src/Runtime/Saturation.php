<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

/**
 * Parses FrankenPHP's Prometheus metrics text into busy/total worker saturation as
 * a percentage. The busy/total *worker* ratio is the load-reactive signal —
 * busy_workers climbs from 0 as requests occupy workers — where the thread pool
 * stays resident regardless of load and reads near-constant even at idle.
 *
 * Split from the scrape so it can be unit-tested against a real payload.
 */
final class Saturation
{
    /**
     * Worker saturation as a percentage, or null when the gauges are absent
     * (metrics off / classic mode) or the reading is physically impossible.
     */
    public static function parse(string $metrics): ?float
    {
        // FrankenPHP emits one gauge line per worker script, optionally
        // Prometheus-labelled (frankenphp_busy_workers{worker="/app/..."} 5). Sum
        // across every entry so a multi-worker app reads its whole pool, not just
        // the first line.
        if (! preg_match_all('/^frankenphp_busy_workers(?:\{[^}]*\})?\s+([0-9.]+)/m', $metrics, $busy)) {
            return null;
        }

        if (! preg_match_all('/^frankenphp_total_workers(?:\{[^}]*\})?\s+([0-9.]+)/m', $metrics, $total)) {
            return null;
        }

        $busyWorkers = array_sum(array_map(floatval(...), $busy[1]));
        $totalWorkers = array_sum(array_map(floatval(...), $total[1]));

        // Reject physically impossible readings. Busy can never exceed total and
        // total can never be zero with a live pool, but both happen transiently mid
        // worker-reload — the scrape catches the two gauges at different instants —
        // yielding an impossible ratio (e.g. 15/4 = 375%) that would clear the burst
        // threshold and false-fire. Clamping to 100 wouldn't help (100 still trips),
        // so the glitch reading is dropped entirely: silent for this tick, and the
        // next scrape re-reads a sane snapshot.
        if ($totalWorkers <= 0.0 || $busyWorkers > $totalWorkers) {
            return null;
        }

        return $busyWorkers / $totalWorkers * 100;
    }
}
