<?php

namespace Codinglabs\Yolo;

use Codinglabs\Yolo\Enums\ServerGroup;

/**
 * One source of truth for how a container shuts down, so supervisord's
 * per-program stop waits and ECS's stopTimeout can't drift apart.
 *
 * Each process shares one key — `shutdown-grace-period`: how long it gets to finish work on
 * SIGTERM before being killed. Octane sits behind the ALB, so its grace doubles
 * as the target group's deregistration delay and the entrypoint drain. The queue
 * worker defaults longer (its in-flight job can outlast an ALB drain); the
 * scheduler inherits octane's. Override a process's window by setting shutdown-grace-period,
 * expanding the opt-in flags to objects where needed:
 *
 *     tasks:
 *       web:
 *         shutdown-grace-period: 10               # web process; also the ALB drain window
 *         queue:
 *           shutdown-grace-period: 90             # let a long job finish before SIGKILL
 *         scheduler: true
 */
class ShutdownTimings
{
    // Queue jobs routinely outlast an ALB drain, so the worker defaults to a
    // longer window than the web process to finish the in-flight job on shutdown.
    public const QUEUE_DEFAULT_GRACE = 70;

    // The web process's graceful-stop window when not set in the manifest.
    private const WEB_DEFAULT_GRACE = 10;

    // A standalone scheduler's graceful-stop window: long enough to let an
    // in-flight schedule:run tick finish. A scheduled command that routinely
    // outlasts this belongs on the queue, not the cron tick.
    private const SCHEDULER_DEFAULT_GRACE = 10;

    // The bundled SSR renderer's graceful-stop window. A render is sub-second and
    // stateless (Inertia falls back to client-side rendering if it's gone), so it
    // only needs a moment to finish an in-flight render before SIGKILL.
    private const SSR_DEFAULT_GRACE = 5;

    // Headroom between the longest graceful stop and ECS's SIGKILL so a process
    // draining right up to its window isn't cut off at the wire.
    private const STOP_TIMEOUT_BUFFER = 5;

    // Fargate hard-caps the container stopTimeout at 120s.
    private const MAX_STOP_TIMEOUT = 120;

    /**
     * The web (octane) process's graceful-stop window. Octane is behind the ALB,
     * so this is also the target group's deregistration delay and how long the
     * entrypoint keeps serving on SIGTERM before forwarding the stop.
     */
    public static function webGrace(): int
    {
        return (int) Manifest::get('tasks.web.shutdown-grace-period', static::WEB_DEFAULT_GRACE);
    }

    /**
     * Seconds the entrypoint keeps serving after SIGTERM before forwarding the
     * stop — the window the ALB needs to stop routing. Zero when headless: with
     * no target group there's nothing to drain, so forward the stop immediately.
     */
    public static function drain(): int
    {
        return Manifest::isHeadless() ? 0 : static::webGrace();
    }

    /**
     * Enabled supervisord program => its graceful-stop window (seconds). Octane
     * always runs; ssr, queue and scheduler are opt-in via tasks.web.*.
     *
     * @return array<string, int>
     */
    public static function programGraces(): array
    {
        $graces = ['octane' => static::webGrace()];

        if (static::enabled('ssr')) {
            $graces['ssr'] = static::grace('ssr', static::SSR_DEFAULT_GRACE);
        }

        if (static::enabled('queue')) {
            $graces['queue'] = static::grace('queue', static::QUEUE_DEFAULT_GRACE);
        }

        if (static::enabled('scheduler')) {
            $graces['scheduler'] = static::grace('scheduler', static::webGrace());
        }

        return $graces;
    }

    /**
     * The ECS SIGKILL ceiling: long enough to cover the drain plus the slowest
     * program's graceful stop (they stop in parallel once the drain forwards the
     * signal), with buffer, but never past Fargate's 120s limit.
     */
    public static function stopTimeout(): int
    {
        return min(
            static::drain() + max(static::programGraces()) + static::STOP_TIMEOUT_BUFFER,
            static::MAX_STOP_TIMEOUT,
        );
    }

    /**
     * The graceful-stop window for a standalone service's sole process, read from
     * its own task block (`tasks.{group}.shutdown-grace-period`). The queue worker
     * defaults longer (an in-flight job can run a while); the scheduler just needs
     * its current cron tick to finish.
     */
    public static function standaloneGrace(ServerGroup $group): int
    {
        return match ($group) {
            ServerGroup::WEB => static::webGrace(),
            ServerGroup::QUEUE => (int) Manifest::get('tasks.queue.shutdown-grace-period', static::QUEUE_DEFAULT_GRACE),
            ServerGroup::SCHEDULER => (int) Manifest::get('tasks.scheduler.shutdown-grace-period', static::SCHEDULER_DEFAULT_GRACE),
        };
    }

    /**
     * ECS's SIGTERM-to-SIGKILL ceiling for a service's task. The web container
     * bundles several processes behind the ALB drain, so it uses the full
     * drain-plus-slowest-program calc. A standalone queue/scheduler runs one
     * process with no ALB to drain, so it just needs its grace plus buffer.
     */
    public static function stopTimeoutFor(ServerGroup $group): int
    {
        if ($group === ServerGroup::WEB) {
            return static::stopTimeout();
        }

        return min(static::standaloneGrace($group) + static::STOP_TIMEOUT_BUFFER, static::MAX_STOP_TIMEOUT);
    }

    protected static function enabled(string $program): bool
    {
        // Whether the web container bundles this program — the object form (with
        // overrides like shutdown-grace-period) means enabled; a bare flag still
        // goes through strict validation so a typo can't silently disable it.
        return Manifest::bundles($program);
    }

    protected static function grace(string $program, int $default): int
    {
        $value = Manifest::get("tasks.web.$program");

        return is_array($value)
            ? (int) ($value['shutdown-grace-period'] ?? $default)
            : $default;
    }
}
