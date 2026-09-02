<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Contracts;

/**
 * Marks a command run under the Admin tier: it assumes the env admin role so the
 * run is capped to YOLO's own blast radius, never the operator's broader
 * identity. The first `yolo sync` runs on the profile and creates the role; every
 * sync after mints it. Minting happens once in the parent before the plan pass
 * forks.
 */
interface AdminCommand {}
