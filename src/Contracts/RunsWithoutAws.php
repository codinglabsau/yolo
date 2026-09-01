<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Contracts;

/**
 * A command that runs against the manifest and the local machine only — no AWS
 * credentials are resolved, no profile is required, no tier is minted. The one
 * occupant is `configure`, which can't require the profile whose creation is
 * its own job. Distinct from InitCommand's early exit: a RunsWithoutAws command
 * still demands a manifest, a valid environment argument, and manifest
 * integrity before it runs.
 */
interface RunsWithoutAws {}
