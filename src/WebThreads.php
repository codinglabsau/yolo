<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

use Codinglabs\Yolo\Enums\ServerGroup;

/**
 * Classic-mode FrankenPHP thread bounds: the floor the pool boots with (`num_threads`) and the
 * ceiling its autoscaler may grow to (`max_threads`), emitted into the generated Caddyfile and
 * read as the tier's concurrency ceiling by {@see WebConcurrency}. Two numbers where the Octane
 * pool has one because a thread holds one request's transient peak, not a resident app copy —
 * so a burst can grow the pool before ECS adds a task. The ceiling is also the burst reporter's
 * denominator, injected as YOLO_BURST_THREADS: FrankenPHP's `total_threads` gauge reports the
 * floor, not the grown count, so a scrape can't stand in for it.
 *
 * Both are pinned because FrankenPHP's auto-detections read the host, not the task: `num_threads`
 * defaults to 2 × visible CPUs (the Fargate microVM's fixed ~2, whatever the task size) and
 * `max_threads auto` derives from host memory, ignoring the container limit. Setting `max_threads`
 * without `num_threads` also silently drops the floor to one thread — emitting both avoids it.
 */
final class WebThreads
{
    /**
     * The same 16 as the Octane pool ({@see WebWorkers}) — an I/O-bound request parks its
     * thread on a downstream, so a task needs more threads than cores — and holding them equal
     * keeps a mode switch from silently changing steady-state capacity.
     */
    private const int THREADS_PER_VCPU = 16;

    /**
     * 2× the floor: enough to absorb a within-minute spike while ECS brings a task up, without
     * every request in the pool degrading together. Sustained load is the task autoscaler's job.
     */
    private const int MAX_THREADS_PER_VCPU = 32;

    /**
     * Lower than the Octane per-worker budget (one request's transient peak, not a resident app),
     * but budgeted against the ceiling since a burst is when every thread is occupied. A
     * conservative starting point, not a measured one.
     */
    private const int THREAD_MEMORY_MB = 48;

    public static function minimum(): int
    {
        return max(1, min(
            (int) round(self::THREADS_PER_VCPU * self::vcpu()),
            self::byMemory(),
        ));
    }

    /** Never below the floor — a memory-starved task collapses to one fixed-size pool, not an invalid range. */
    public static function maximum(): int
    {
        return max(self::minimum(), min(
            (int) round(self::MAX_THREADS_PER_VCPU * self::vcpu()),
            self::byMemory(),
        ));
    }

    /** Fargate CPU units ÷ 1024 — the honest allocation, not anything readable inside the microVM. */
    private static function vcpu(): float
    {
        return (int) Manifest::get('tasks.web.cpu', ServerGroup::WEB->defaultCpu()) / 1024;
    }

    private static function byMemory(): int
    {
        $memoryMb = (int) Manifest::get('tasks.web.memory', ServerGroup::WEB->defaultMemory());

        return intdiv($memoryMb, self::THREAD_MEMORY_MB);
    }
}
