<?php

namespace Codinglabs\Yolo;

/**
 * One source of truth for how the web container shuts down, so supervisord's
 * per-program stop waits and ECS's stopTimeout can't drift apart.
 *
 * Everything inherits the deregistration delay — the single knob you tune for a
 * given app — except the queue worker, whose in-flight job can outlast any ALB
 * drain and so gets its own longer default. Tune a process's window only when it
 * genuinely differs, by expanding its manifest flag to an object:
 *
 *     tasks:
 *       web:
 *         deregistration-delay: 10     # ALB drain; octane + scheduler inherit it
 *         queue:
 *           stop-grace: 90             # let a long job finish before SIGKILL
 */
class ShutdownTimings
{
    // Queue jobs routinely outlast an ALB drain, so the worker defaults to a
    // longer window than the web tier to finish the in-flight job on shutdown.
    public const QUEUE_DEFAULT_GRACE = 70;

    // Headroom between the longest graceful stop and ECS's SIGKILL so a process
    // draining right up to its window isn't cut off at the wire.
    private const STOP_TIMEOUT_BUFFER = 5;

    // Fargate hard-caps the container stopTimeout at 120s.
    private const MAX_STOP_TIMEOUT = 120;

    public static function deregistrationDelay(): int
    {
        return (int) Manifest::get('tasks.web.deregistration-delay', 10);
    }

    /**
     * Seconds the entrypoint keeps serving after SIGTERM before forwarding the
     * stop — the window the ALB needs to stop routing. Zero when headless: with
     * no target group there's nothing to drain, so forward the stop immediately.
     */
    public static function drain(): int
    {
        return Manifest::isHeadless() ? 0 : static::deregistrationDelay();
    }

    /**
     * Enabled supervisord program => its graceful-stop window (seconds). Octane
     * always runs; queue and scheduler are opt-in via tasks.web.*.
     *
     * @return array<string, int>
     */
    public static function programGraces(): array
    {
        $graces = ['octane' => static::deregistrationDelay()];

        if (static::enabled('queue')) {
            $graces['queue'] = static::grace('queue', static::QUEUE_DEFAULT_GRACE);
        }

        if (static::enabled('scheduler')) {
            $graces['scheduler'] = static::grace('scheduler', static::deregistrationDelay());
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
