<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

/**
 * Ambient flag: true only while `destroy:environment` runs. `Lifecycle::state()` consults it to
 * force every env-backed service to Teardown regardless of what the env manifest still
 * declares. A normal `sync` never sets it.
 */
final class Destroying
{
    private static bool $active = false;

    public static function active(): bool
    {
        return self::$active;
    }

    /**
     * Restores the prior value rather than hard-resetting, so nested runs unwind cleanly.
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
