<?php

namespace Codinglabs\Yolo;

/**
 * Ambient channel for surfacing progress from inside a blocking AWS waiter or a shell-out
 * step. Resources own their `waitUntil` and stay UI-agnostic, and the waiter's before-attempt
 * callback is the only code that runs during the wait — so `Aws::waitFor()` pings `poll()`
 * and the runner registers a reporter around a LongRunning step. `RunsProcess` pumps `line()`
 * from its read loop so a docker/npm build shows its live output instead of freezing.
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

    /** Blank lines are ignored so the last meaningful line stays on screen through a quiet stretch. */
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
