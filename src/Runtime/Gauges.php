<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

use RuntimeException;
use Codinglabs\Yolo\WebThreads;

/**
 * Reads FrankenPHP's gauges from its Prometheus metrics text — the pool-size and
 * queue signals the burst reporter needs and can't know without asking FrankenPHP.
 *
 * Worker mode (Octane) exposes the worker gauges; classic mode has no worker script,
 * so it exposes only the thread gauges. `busy_workers` over `total_workers` is the
 * worker-mode ratio: the busy gauge counts every request dispatched to the worker
 * script, those still waiting for a worker included, so under overload it carries the
 * front queue and reads past the pool rather than stalling at it. `total_threads` is
 * *not* a usable denominator: it reports the pinned floor (`num_threads`), not the
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

    /**
     * Requests dispatched to the worker scripts, summed the same way as the total —
     * queued ones included, so it can exceed the pool.
     */
    public static function busyWorkers(string $metrics): int
    {
        return self::companion($metrics, 'frankenphp_busy_workers', 'frankenphp_total_workers');
    }

    /** Whether the thread gauges are present — both modes emit them, so absence means metrics are off. */
    public static function hasThreads(string $metrics): bool
    {
        return self::sum($metrics, 'frankenphp_total_threads') !== null;
    }

    public static function busyThreads(string $metrics): int
    {
        return self::companion($metrics, 'frankenphp_busy_threads', 'frankenphp_total_threads');
    }

    public static function queueDepth(string $metrics): int
    {
        return self::companion($metrics, 'frankenphp_queue_depth', 'frankenphp_total_threads');
    }

    /**
     * Every gauge and counter the burst diagnostics log, keyed by the FrankenPHP name
     * minus its prefix; null where a line is absent. Diagnostic only — nothing here
     * feeds the saturation formula — so a missing line is reported, never thrown on.
     * `ready_workers` and the two counters are the ones that separate "workers
     * counted" from "workers alive": a pool whose ready count sits under its total, or
     * whose crash/restart counters climb, is serving fewer requests than it was sized
     * for, which no numerator can correct.
     *
     * @return array<string, int|null>
     */
    public static function diagnostics(string $metrics): array
    {
        $names = [
            'total_workers', 'ready_workers', 'busy_workers',
            'worker_queue_depth', 'worker_crashes', 'worker_restarts',
            'total_threads', 'busy_threads', 'queue_depth',
        ];

        $diagnostics = [];

        foreach ($names as $name) {
            $diagnostics[$name] = self::sum($metrics, "frankenphp_{$name}");
        }

        return $diagnostics;
    }

    /**
     * A gauge that must accompany its family's total — FrankenPHP registers each family
     * together, so one missing beside the others is a broken scrape, not an idle pool,
     * and defaulting it to zero would silently under-report saturation.
     */
    private static function companion(string $metrics, string $gauge, string $total): int
    {
        return self::sum($metrics, $gauge)
            ?? throw new RuntimeException("FrankenPHP metrics carry {$total} but no {$gauge}.");
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
