<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\AdminRole;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

/**
 * Self-activating: the first sync (role absent) runs on the profile and creates
 * it; later syncs mint it.
 */
class SyncAdminRoleStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new AdminRole(), $options);
    }
}
