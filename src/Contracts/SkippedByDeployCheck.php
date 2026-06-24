<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Contracts;

use Codinglabs\Yolo\DeployCheck;

/**
 * Marks a sync step a read-only `sync --check` tier must NOT run.
 *
 * Two callers run the full `sync --check` under a least-privilege read tier: the
 * pre-deploy in-sync gate (EnsureInSyncStep, *deployer* tier) and the `audit`
 * health check (AuditCommand, *Observer* tier). These steps reconcile
 * env-backed-service state a read tier is deliberately fenced from reading:
 *
 * - the env-shared Typesense admin key + env-tier log-group tags — fenced from
 *   both the deployer and the Observer; and
 * - an app's per-app `.env` (env/.env.{app}, holding its minted Typesense keys) —
 *   fenced from the Observer (the deployer may read it, but minting needs the admin
 *   key it can't, so it can't reconcile this anyway).
 *
 * Running them under a fenced tier 403s, and that tier can't reconcile them regardless,
 * so the gate/audit skips them: `yolo sync <env>` (admin) is their drift check. The
 * skip is scoped via {@see DeployCheck}; a direct admin `yolo sync` / `sync --check`
 * runs them as normal.
 */
interface SkippedByDeployCheck {}
