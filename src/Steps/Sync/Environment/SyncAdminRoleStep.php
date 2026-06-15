<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\AdminRole;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

/**
 * Provisions the env-shared `yolo-{env}-admin-role` an operator assumes to run
 * `yolo sync` / `yolo scale` capped to YOLO's blast radius. Unconditional — it
 * stands up with the environment; the read + write policies are attached by
 * {@see AttachAdminRolePolicyStep}, which runs after this. Self-activating: the
 * first sync (role absent) runs on the profile and creates it; later syncs mint it.
 */
class SyncAdminRoleStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new AdminRole(), $options);
    }
}
