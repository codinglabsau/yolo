<?php

namespace Codinglabs\Yolo;

/**
 * Ambient channel the stepped-command runner uses to surface progress from
 * deep inside a blocking AWS waiter.
 *
 * Resources own their `waitUntil` call and stay UI-agnostic, and a waiter
 * blocks the main thread so the runner can't tick the progress bar on its own.
 * The waiter's before-attempt callback is the only code that runs during the
 * wait, so `Aws::waitFor()` pings `poll()` on each attempt and the runner
 * registers a reporter (`using()`) around a LongRunning step to turn those
 * pings into a redraw — then clears it afterwards. No reporter registered makes
 * `poll()` a no-op, so non-LongRunning waiters are unaffected.
 *
 * A shell-out step (docker build, npm/composer install) has no AWS waiter, so
 * `RunsProcess` drives the same channel from its read loop: it pumps `line()`
 * with the child's latest output and `poll()` to redraw. The runner's heartbeat
 * prefers the live line over the static patience message, so the bar shows what
 * the build is actually doing instead of freezing for ~2 minutes.
 */
class WaitReporter
{
    /** @var (callable(): void)|null */
    protected static $reporter;

    protected static ?string $message = null;

    public static function using(?callable $reporter): void
    {
        static::$reporter = $reporter;
    }

    public static function poll(): void
    {
        if (static::$reporter !== null) {
            (static::$reporter)();
        }
    }

    /**
     * The latest line of progress from inside the running step — a child
     * process's stdout/stderr. Blank lines are ignored so the last meaningful
     * line stays on screen through a quiet stretch.
     */
    public static function line(string $line): void
    {
        $line = trim($line);

        if ($line !== '') {
            static::$message = $line;
        }
    }

    public static function message(): ?string
    {
        return static::$message;
    }

    public static function clear(): void
    {
        static::$reporter = null;
        static::$message = null;
    }
}
