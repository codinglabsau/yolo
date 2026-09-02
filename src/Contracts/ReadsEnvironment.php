<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Contracts;

use Codinglabs\Yolo\Commands\Command;

/**
 * Marks a {@see ReadOnlyCommand} whose reads span the whole environment, so it
 * caps to the env observer role; an unmarked one caps to the narrower per-app
 * observer role so a read grant can be scoped to one app.
 * See {@see Command::observerRole()}.
 */
interface ReadsEnvironment {}
