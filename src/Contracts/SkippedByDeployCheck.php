<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Contracts;

use Codinglabs\Yolo\DeployCheck;

/**
 * Marks a sync step the pre-deploy in-sync gate (EnsureInSyncStep) must NOT run.
 *
 * The gate runs the full `sync --check` under the least-privilege *deployer*
 * tier, but these steps reconcile admin-owned env-backed-service state the
 * deployer is deliberately fenced from reading — the env-shared admin key (the
 * Typesense cluster key in `.env.environment.{env}`) and env-tier log-group
 * tags. Running them under the deployer 403s, and the deployer can't reconcile
 * them anyway, so the gate skips them: `yolo sync <env>` (admin) is their drift
 * check. The skip is scoped to the gate (via {@see DeployCheck});
 * a direct `yolo sync` / `sync --check` runs them as normal.
 */
interface SkippedByDeployCheck {}
