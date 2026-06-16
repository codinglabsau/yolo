<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Contracts;

use Codinglabs\Yolo\Commands\Command;

/**
 * Marker for a {@see ReadOnlyCommand} whose reads span the WHOLE environment
 * rather than a single app — the env status roll-up and every `audit` verb
 * (audit queries the env-wide tag namespace, then filters client-side). These
 * cap to the env observer role; an unmarked read command caps to the narrower
 * per-app observer role (log content fenced to its app), so a read grant can be
 * scoped to one app. See {@see Command::observerRole()}.
 */
interface ReadsEnvironment {}
