<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

use Codinglabs\Yolo\Enums\ServerGroup;

/**
 * The classic-mode web tier's FrankenPHP thread bounds — the pool it starts with
 * (`num_threads`) and the ceiling its own thread autoscaler may grow to
 * (`max_threads`) — emitted into the generated Caddyfile by
 * {@see Steps\Build\Fargate\GenerateSupervisorConfigStep} and read as the tier's
 * concurrency ceiling by {@see WebConcurrency}.
 *
 * Classic mode (`tasks.web.octane: false`) boots the framework per request, so a
 * thread is not the resident app copy an Octane worker is ({@see WebWorkers}) — it
 * holds one request's transient peak and gives it back. That asymmetry is why this
 * tier gets two numbers where the worker pool gets one: a floor sized for the steady
 * state, and a ceiling FrankenPHP grows into under a within-minute burst, before ECS
 * can add a task. A thread is still the concurrency unit either way — it serves one
 * request at a time and blocks for that request's whole lifetime, downstream waits
 * included — so the ceiling is the per-task concurrency ceiling.
 *
 * Both numbers are pinned rather than left to FrankenPHP, because both of its
 * auto-detections read the host rather than the task:
 *
 *  - `num_threads` defaults to 2 × the CPUs visible to the process. On Fargate that
 *    is the Firecracker microVM's fixed ~2 vCPUs whatever the task is sized to — the
 *    same microVM lie documented on {@see WebWorkers} — pinning ~4 threads on a 0.25
 *    vCPU and a 2 vCPU task alike, and immovable by resizing the task.
 *  - `max_threads auto` derives its ceiling from host memory and does not consult the
 *    container's memory limit at all: the same ceiling comes back whether the
 *    container is capped at 512 MB or 4 GB. Left on `auto`, a small task would grow a
 *    pool it cannot physically hold, so YOLO always emits an explicit number.
 *
 * (`auto` carries a second trap worth naming: setting `max_threads` *without*
 * `num_threads` silently drops the floor to a single thread. YOLO emits both, so the
 * combination that triggers it can't arise.)
 */
final class WebThreads
{
    /**
     * Threads per allocated vCPU at rest — the floor the pool boots with. Deliberately
     * the same 16 the Octane pool uses ({@see WebWorkers}): the reason for exceeding
     * FrankenPHP's 2×-CPU default is the same in both modes (an I/O-bound request
     * parks its thread on a downstream rather than burning the core, so a task needs
     * more threads than cores to stay busy), and holding the two equal keeps a mode
     * switch from silently changing steady-state capacity.
     */
    private const int THREADS_PER_VCPU = 16;

    /**
     * Threads per allocated vCPU at full stretch — the ceiling the thread autoscaler
     * may reach. 2× the floor: enough to absorb a within-minute arrival spike while
     * ECS brings another task up, without letting one task accept so much work that
     * every request in the pool degrades together. Sustained load is the task
     * autoscaler's job, not this one's.
     */
    private const int MAX_THREADS_PER_VCPU = 32;

    /**
     * The memory a busy thread is budgeted, and so the divisor for the memory bound
     * on both numbers. Lower than the Octane pool's per-worker budget ({@see WebWorkers})
     * because this is one request's transient peak rather than a resident app copy — but budgeted
     * against the *ceiling*, since a burst is exactly when every thread is occupied
     * at once. A conservative starting point, not a measured one.
     */
    private const int THREAD_MEMORY_MB = 48;

    /**
     * The pool's floor (`num_threads`): `16 × real vCPU`, capped by what memory can
     * hold and never below one.
     */
    public static function minimum(): int
    {
        return max(1, min(
            (int) round(self::THREADS_PER_VCPU * self::vcpu()),
            self::byMemory(),
        ));
    }

    /**
     * The pool's ceiling (`max_threads`): `32 × real vCPU`, capped by what memory can
     * hold, and never below the floor (a memory-starved task collapses to a single
     * fixed-size pool rather than an invalid range).
     */
    public static function maximum(): int
    {
        return max(self::minimum(), min(
            (int) round(self::MAX_THREADS_PER_VCPU * self::vcpu()),
            self::byMemory(),
        ));
    }

    /**
     * The task's real vCPU allocation — Fargate CPU units ÷ 1024, the same honest
     * number injected as `YOLO_BURST_CPU` — rather than anything readable from inside
     * the microVM.
     */
    private static function vcpu(): float
    {
        return (int) Manifest::get('tasks.web.cpu', ServerGroup::WEB->defaultCpu()) / 1024;
    }

    /**
     * How many concurrently-busy threads the task's memory allocation can hold.
     */
    private static function byMemory(): int
    {
        $memoryMb = (int) Manifest::get('tasks.web.memory', ServerGroup::WEB->defaultMemory());

        return intdiv($memoryMb, self::THREAD_MEMORY_MB);
    }
}
