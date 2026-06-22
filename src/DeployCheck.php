<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

/**
 * Ambient flag: true only while the deploy preflight gate (EnsureInSyncStep) is
 * running its `sync --check`. The step runner consults it (via
 * ChecksIfCommandsShouldBeRunning::skipReason) to skip SkippedByDeployCheck
 * steps — admin-owned env-backed-service reconcilers the deployer tier can't
 * read. Kept out of the sync command itself so the gate reuses `sync` verbatim;
 * a direct `yolo sync` / `sync --check` (admin, CI drift) never sets it, so it
 * keeps checking everything.
 */
final class DeployCheck
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
