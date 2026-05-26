<?php

namespace Codinglabs\Yolo;

/**
 * One source of truth for how the web container shuts down, so supervisord's
 * per-program stop waits and ECS's stopTimeout can't drift apart.
 *
 * Each process shares one key — `stop-grace`: how long it gets to finish work on
 * SIGTERM before being killed. Octane sits behind the ALB, so its grace doubles
 * as the target group's deregistration delay and the entrypoint drain. The queue
 * worker defaults longer (its in-flight job can outlast an ALB drain); the
 * scheduler inherits octane's. Override a process's window by setting stop-grace,
 * expanding the opt-in flags to objects where needed:
 *
 *     tasks:
 *       web:
 *         stop-grace: 10               # web process; also the ALB drain window
 *         queue:
 *           stop-grace: 90             # let a long job finish before SIGKILL
 *         scheduler: true
 */
class ShutdownTimings
{
    // Queue jobs routinely outlast an ALB drain, so the worker defaults to a
    // longer window than the web process to finish the in-flight job on shutdown.
    public const QUEUE_DEFAULT_GRACE = 70;

    // The web process's graceful-stop window when not set in the manifest.
    private const WEB_DEFAULT_GRACE = 10;

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
    public static function webStopGrace(): int
    {
        return (int) Manifest::get('tasks.web.stop-grace', static::WEB_DEFAULT_GRACE);
    }

    /**
     * Seconds the entrypoint keeps serving after SIGTERM before forwarding the
     * stop — the window the ALB needs to stop routing. Zero when headless: with
     * no target group there's nothing to drain, so forward the stop immediately.
     */
    public static function drain(): int
    {
        return Manifest::isHeadless() ? 0 : static::webStopGrace();
    }

    /**
     * Enabled supervisord program => its graceful-stop window (seconds). Octane
     * always runs; queue and scheduler are opt-in via tasks.web.*.
     *
     * @return array<string, int>
     */
    public static function programGraces(): array
    {
        $graces = ['octane' => static::webStopGrace()];

        if (static::enabled('queue')) {
            $graces['queue'] = static::grace('queue', static::QUEUE_DEFAULT_GRACE);
        }

        if (static::enabled('scheduler')) {
            $graces['scheduler'] = static::grace('scheduler', static::webStopGrace());
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

    protected static function enabled(string $program): bool
    {
        $value = Manifest::get("tasks.web.$program", false);

        // The object form (with overrides like stop-grace) means enabled; a bare
        // flag still goes through strict validation so a typo can't silently
        // disable a process.
        return is_array($value) || Helpers::validateStrictBool($value, "tasks.web.$program");
    }

    protected static function grace(string $program, int $default): int
    {
        $value = Manifest::get("tasks.web.$program");

        return is_array($value)
            ? (int) ($value['stop-grace'] ?? $default)
            : $default;
    }
}
