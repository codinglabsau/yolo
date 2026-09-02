<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

use Codinglabs\Yolo\Enums\ServerGroup;

/**
 * The Octane web tier's FrankenPHP worker-pool size — the single source of truth for the
 * `octane:start --workers` pin ({@see ProcessCommands::web()}) and the autoscaling concurrency
 * target ({@see WebConcurrency}). Classic-mode counterpart: {@see WebThreads}.
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
     * Above FrankenPHP's 2×-CPU default because an I/O-bound request parks its worker on a
     * downstream rather than burning the core. A conservative floor, not a measured answer:
     * the principled ceiling is where CPU becomes the binding constraint under target
     * concurrency, which for bundled SSR sits below the memory cap (16→32 on a 2 GB task is
     * the range a load test would explore). Hardcoded — no override case yet.
     */
    private const int WORKERS_PER_VCPU = 16;

    /**
     * The outer safety bound, not the target (~64 MB per resident app copy). Only binds on a
     * deliberately memory-starved task; for every standard Fargate pair the vCPU term is smaller.
     */
    private const int WORKER_MEMORY_MB = 64;

    /** `real vCPU` is Fargate CPU units ÷ 1024 — the same honest allocation injected as `YOLO_BURST_CPU`. */
    public static function count(): int
    {
        $cpuUnits = (int) Manifest::get('tasks.web.cpu', ServerGroup::WEB->defaultCpu());
        $memoryMb = (int) Manifest::get('tasks.web.memory', ServerGroup::WEB->defaultMemory());

        $byCpu = (int) round(self::WORKERS_PER_VCPU * ($cpuUnits / 1024));
        $byMemory = intdiv($memoryMb, self::WORKER_MEMORY_MB);

        return max(1, min($byCpu, $byMemory));
    }
}
