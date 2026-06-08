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
 */
class WaitReporter
{
    /** @var (callable(): void)|null */
    protected static $reporter;

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

    public static function clear(): void
    {
        static::$reporter = null;
    }
}
