<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

use Codinglabs\Yolo\Enums\ServerGroup;

/**
 * The web tier's FrankenPHP worker-pool size — how many requests one web task can
 * serve **concurrently** — and the single source of truth shared by the runtime
 * (the `octane:start --workers` pin in {@see ProcessCommands::web()}) and the
 * autoscaling concurrency target ({@see Resources\ApplicationAutoScaling\WebConcurrencyPolicy}).
 *
 * Why pin it at all: a FrankenPHP worker handles one request at a time and blocks
 * for that request's whole lifetime — including any wait on a downstream the worker
 * can't yield during (a DB query, an Inertia SSR render). So the pool size *is* the
 * concurrency ceiling: with N workers a task can serve at most N requests at once,
 * the (N+1)th queues. Octane's per-request speed (no framework re-boot) raises
 * throughput but not this ceiling — it does nothing for the I/O-wait slice of a hold.
 *
 * Why not leave it to FrankenPHP's auto-detection: that derives the count from the
 * CPUs *visible to the process* (Go's `runtime.NumCPU`), which on Fargate is the
 * Firecracker microVM's vCPU count — **not** the task's CPU allocation. The microVM
 * presents ~2 vCPUs whatever the task is sized to, so auto-detect pins ~4 workers on
 * a 0.25, 0.5 and 1.0 vCPU task alike: too few for an I/O-blocking request, and
 * immovable by resizing the task. (Same microVM lie YOLO already works around for the
 * burst CPU signal by injecting the real allocation as `YOLO_BURST_CPU`.)
 *
 * So size the pool off the *honest* allocation instead: workers scale with the task's
 * real vCPU, so a 0.5 vCPU task runs half the workers of a 1 vCPU one. That keeps the
 * concurrency ceiling matched to the compute that has to clear the work — a small task
 * accepts proportionally less and can't over-commit itself into a health-check
 * death-loop. Bounded above by memory, since a resident Octane worker holds a full app
 * copy, so the pool can never exceed what the task can physically hold.
 */
final class WebWorkers
{
    /**
     * Workers per allocated vCPU. Higher than FrankenPHP's 2×-CPU default because an
     * SSR / I/O-bound request parks its worker on a downstream render rather than
     * burning the core, so a task needs more resident workers than cores to stay busy
     * without the pool becoming the artificial bottleneck.
     *
     * 16 is a deliberately conservative *floor*, not a measured answer. It lifts a 1 vCPU
     * task off the ~4-worker auto-detect floor while keeping a 0.5 vCPU task (half the
     * compute → a shallower real ceiling) healthy rather than queuing CPU-bound renders
     * into a death-loop. The principled ceiling is where **CPU becomes the binding
     * constraint** under target concurrency — for bundled SSR (PHP serialise + the Node
     * render burning the same cores) that point sits *below* the memory cap, so it's the
     * value the load test finds by turning this dial up (on a 2 GB task memory caps the
     * pool at ~32, so the range to explore is 16→32). Hardcoded rather than a manifest
     * knob — one number, no override case yet.
     */
    private const int WORKERS_PER_VCPU = 16;

    /**
     * The memory ceiling: a resident Octane worker holds a full app copy (~64 MB is a
     * conservative estimate for a typical Laravel app). This is the **outer safety
     * bound, not the target** — the value the pool should actually settle at is the
     * lower CPU-throughput point ({@see WORKERS_PER_VCPU}). It only binds on a
     * deliberately memory-starved task; for every standard Fargate CPU/memory pair the
     * vCPU term is the smaller one.
     */
    private const int WORKER_MEMORY_MB = 64;

    /**
     * The pinned worker count for the web tier: `16 × real vCPU`, capped by memory,
     * never below one. `real vCPU` is the task's Fargate CPU units ÷ 1024 (the same
     * honest allocation injected as `YOLO_BURST_CPU`), so the pool tracks the task size
     * a deploy actually buys rather than the microVM's fixed ~2.
     */
    public static function count(): int
    {
        $cpuUnits = (int) Manifest::get('tasks.web.cpu', ServerGroup::WEB->defaultCpu());
        $memoryMb = (int) Manifest::get('tasks.web.memory', ServerGroup::WEB->defaultMemory());

        $byCpu = (int) round(self::WORKERS_PER_VCPU * ($cpuUnits / 1024));
        $byMemory = intdiv($memoryMb, self::WORKER_MEMORY_MB);

        return max(1, min($byCpu, $byMemory));
    }
}
