<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Contracts;

use Codinglabs\Yolo\DeployCheck;

/**
 * Marks a sync step the read-tier `sync --check` callers (the pre-deploy gate,
 * `audit`) must NOT run: it reconciles admin-owned state (the env-shared service
 * admin key, env log-group tags, per-app minted keys, the version-of-record stamp)
 * that a read tier is fenced from and couldn't reconcile anyway, so it would 403.
 * `yolo sync <env>` (admin) is its drift check; the skip is scoped via
 * {@see DeployCheck}, so a direct admin `sync --check` still runs it.
 */
interface SkippedByDeployCheck {}
