<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

use Codinglabs\Yolo\WebThreads;

/**
 * Reads FrankenPHP's gauges from its Prometheus metrics text — the pool-size and
 * queue signals the burst reporter needs and can't know without asking FrankenPHP.
 *
 * Worker mode (Octane) exposes the worker gauges; classic mode has no worker script,
 * so it exposes only the thread gauges. `total_workers` is a static gauge that reads
 * correctly even while the box is pinned, unlike `busy_workers`, whose after-response
 * snapshot under-reports the very pin burst exists to catch — which is why the worker-
 * mode numerator comes from {@see InFlightRequests}, counted directly. `total_threads`
 * is *not* a usable denominator: it reports the pinned floor (`num_threads`), not the
 * count the thread autoscaler has grown to, so `busy_threads` can legitimately exceed
 * it. The classic denominator is the `max_threads` ceiling YOLO itself pinned
 * ({@see WebThreads}). `queue_depth` is the classic tier's direct
 * burst signal: a non-zero reading means a request is waiting for a thread.
 *
 * Split from the scrape so it can be unit-tested against a real payload.
 */
final class Gauges
{
    /**
     * The worker count summed across every worker-script gauge entry, or null when the
     * gauge is absent (metrics off / classic mode) or zero (caught mid worker-reload),
     * neither of which is a usable denominator.
     */
    public static function totalWorkers(string $metrics): ?int
    {
        $total = self::sum($metrics, 'frankenphp_total_workers');

        return $total > 0 ? $total : null;
    }

    /** Whether the thread gauges are present at all — the classic-mode signature. */
    public static function hasThreads(string $metrics): bool
    {
        return self::sum($metrics, 'frankenphp_total_threads') !== null;
    }

    public static function busyThreads(string $metrics): int
    {
        return self::sum($metrics, 'frankenphp_busy_threads') ?? 0;
    }

    public static function queueDepth(string $metrics): int
    {
        return self::sum($metrics, 'frankenphp_queue_depth') ?? 0;
    }

    /**
     * A gauge's value summed across every entry, or null when it is absent. FrankenPHP
     * emits one line per worker script for the worker gauges, optionally Prometheus-
     * labelled (frankenphp_total_workers{worker="/app/..."} 4), so summing reads a
     * multi-worker app's whole pool rather than just the first line.
     */
    private static function sum(string $metrics, string $gauge): ?int
    {
        if (! preg_match_all('/^' . preg_quote($gauge, '/') . '(?:\{[^}]*\})?\s+([0-9.]+)/m', $metrics, $matches)) {
            return null;
        }

        return (int) array_sum(array_map(floatval(...), $matches[1]));
    }
}
