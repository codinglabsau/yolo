<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

/**
 * Ambient flag: true only while a read-only tier is running `sync --check` and
 * must skip the admin-owned env-backed-service reconcilers it can't read — the
 * deploy preflight gate (EnsureInSyncStep, deployer tier) and the `audit` health
 * check (AuditCommand, Observer tier). The step runner consults it (via
 * ChecksIfCommandsShouldBeRunning::skipReason) to skip SkippedByDeployCheck
 * steps. Kept out of the sync command itself so both reuse `sync` verbatim; a
 * direct `yolo sync` / `sync --check` (admin, CI drift) never sets it, so it
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
