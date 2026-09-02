<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

/**
 * Ambient flag: true only while a read-only tier runs `sync --check` and must skip the
 * admin-owned env-backed-service reconcilers it can't read (the deploy preflight gate and the
 * `audit` health check). Kept out of the sync command so both reuse `sync` verbatim; a direct
 * `yolo sync` never sets it, so it keeps checking everything.
 */
final class DeployCheck
{
    private static bool $active = false;

    public static function active(): bool
    {
        return self::$active;
    }

    /**
     * Restores the prior value rather than hard-resetting, so the gate's two check passes nest cleanly.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function during(callable $callback): mixed
    {
        $previous = self::$active;
        self::$active = true;

        try {
            return $callback();
        } finally {
            self::$active = $previous;
        }
    }
}
