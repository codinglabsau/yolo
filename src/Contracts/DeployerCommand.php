<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Contracts;

use Codinglabs\Yolo\Resources\Iam\DeployerPolicy;

/**
 * Marks a command run under the deploy tier: it assumes the app's deployer role
 * so the run is capped to exactly the {@see DeployerPolicy} CI deploys under,
 * never the developer's broader identity. Until `sync:app` creates the role,
 * minting is a no-op and the command runs on the profile.
 */
interface DeployerCommand {}
