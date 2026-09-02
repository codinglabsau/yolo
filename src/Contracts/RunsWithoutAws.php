<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Contracts;

/**
 * No AWS credentials resolved, no profile required, no tier minted — `configure`
 * can't require the profile whose creation is its own job. Still demands a
 * manifest and a valid environment, unlike InitCommand's early exit.
 */
interface RunsWithoutAws {}
