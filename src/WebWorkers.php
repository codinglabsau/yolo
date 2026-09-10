<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

use Codinglabs\Yolo\Enums\ServerGroup;

/**
 * The Octane web tier's FrankenPHP worker-pool size — the single source of truth for the
 * `octane:start --workers` pin ({@see ProcessCommands::web()}) and the autoscaling concurrency
 * target ({@see WebConcurrency}). The burst reporter reads the pool back from FrankenPHP's
 * total_workers gauge instead, since Octane owns it at runtime. Classic-mode counterpart:
 * {@see WebThreads}.
 *
 * A worker handles one request at a time and blocks for its whole lifetime, downstream waits
 * included, so the pool size IS the per-task concurrency ceiling. It's pinned because
 * FrankenPHP's auto-detection reads the CPUs visible to the process — on Fargate the
 * Firecracker microVM's ~2 vCPUs whatever the task is sized to — so it lands ~4 workers on a
 * 0.25 and a 1.0 vCPU task alike, immovable by resizing. Sizing off the real allocation keeps
 * the ceiling matched to the compute that has to clear the work, bounded above by memory.
 */
final class WebWorkers
{
    /**
     * Above FrankenPHP's 2×-CPU default because a request that parks its worker on a downstream
     * isn't burning the core, but half the 16 a purely I/O-bound pool would justify. Load
     * testing a CPU-bound app measured throughput flat across pool sizes; what differs is how
     * the two mistakes fail. Too few workers fails visibly — requests queue at the load balancer,
     * where the saturation metric and the autoscaler both see them. Too many fails invisibly:
     * the excess queues inside the task (each holding a database connection), the load balancer
     * sees healthy latency, and the saturation metric under-reads. Sizing errs toward the visible
     * failure. `tasks.web.concurrency` sets the count directly for an app that knows better in
     * either direction — a genuinely I/O-bound workload wanting the larger pool included.
     */
    private const int WORKERS_PER_VCPU = 8;

    /**
     * The outer safety bound, not the target (~64 MB per resident app copy). Only binds on a
     * deliberately memory-starved task; for every standard Fargate pair the vCPU term is at
     * most a quarter of it (1 vCPU / 2 GB derives 8 by CPU against 32 by memory).
     */
    private const int WORKER_MEMORY_MB = 64;

    /**
     * An explicit `tasks.web.concurrency` is taken verbatim — absolute, not per vCPU, and not
     * memory-capped: the operator has sized it. Otherwise `real vCPU` is Fargate CPU units ÷
     * 1024 — the same honest allocation injected as `YOLO_BURST_CPU`.
     */
    public static function count(): int
    {
        if (($concurrency = Manifest::webConcurrency()) !== null) {
            return $concurrency;
        }

        $cpuUnits = (int) Manifest::get('tasks.web.cpu', ServerGroup::WEB->defaultCpu());
        $memoryMb = (int) Manifest::get('tasks.web.memory', ServerGroup::WEB->defaultMemory());

        $byCpu = (int) round(self::WORKERS_PER_VCPU * ($cpuUnits / 1024));
        $byMemory = intdiv($memoryMb, self::WORKER_MEMORY_MB);

        return max(1, min($byCpu, $byMemory));
    }
}
