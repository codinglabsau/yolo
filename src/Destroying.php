<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

/**
 * Ambient flag: true only while `destroy:environment` is tearing an environment
 * down. `Lifecycle::state()` consults it to force every env-backed service to
 * Teardown regardless of whether the env manifest still declares it, so a full
 * environment teardown removes the service stacks too. A normal `sync` never sets
 * it.
 */
final class Destroying
{
    private static bool $active = false;

    public static function active(): bool
    {
        return self::$active;
    }

    /**
     * Run $callback with the deploy-check flag set, restoring the prior value
     * afterwards (restore, not hard-reset, so the gate's two check passes — pre-
     * and post-reconcile — nest cleanly).
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
