<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\DeployerRole;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncDeployerRoleStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (Helpers::githubRepository() === null) {
            return StepResult::SKIPPED;
        }

        // Trust-policy drift (the manifest repository/branch/tag changed) rides
        // through SynchronisesConfiguration on the role, so it's recorded in the
        // plan pass and survives the only-pending-steps filter into apply.
        return $this->syncResource(new DeployerRole(), $options);
    }
}
