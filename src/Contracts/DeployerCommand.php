<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Contracts;

use Codinglabs\Yolo\Resources\Iam\DeployerPolicy;

/**
 * Marker for a command that mutates only what `yolo deploy` touches — the deploy
 * lifecycle (`deploy` / `build` / `run`). YOLO runs these under the deploy tier:
 * it assumes the app's `yolo-{env}-{app}-deployer` role and re-registers every AWS
 * client against the resulting scoped token, so the run is capped to exactly the
 * deploy-time permission set (the same {@see DeployerPolicy}
 * CI deploys under) — never the developer's broader identity.
 *
 * The deployer role's primary trust is GitHub OIDC (CI, repo + ref scoped); the
 * same-account assumption that lets a developer mint this tier locally is the
 * secondary trust statement. Provisioning the app (`sync:app`) is the opt-in:
 * until the role exists, minting is a no-op and the command runs on the profile.
 */
interface DeployerCommand {}
