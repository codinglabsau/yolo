<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\DeployersGroup;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

/**
 * Provisions this app's deployers grant group — membership grants deploy on this
 * app only. Gated on a GitHub repository exactly like the deployer role it points
 * at: with no repo there is no deployer role, so the group would grant assumption
 * of a non-existent role.
 */
class SyncDeployersGroupStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (Helpers::githubRepository() === null) {
            return StepResult::SKIPPED;
        }

        return $this->syncResource(new DeployersGroup(), $options);
    }
}
