<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\AdminsGroup;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

/**
 * Provisions the env admins grant group — membership grants sync/scale across
 * the environment (and the account-tier sync the env admin role carries). YOLO
 * owns the group + its assume-role policy; membership is the human lever.
 */
class SyncAdminsGroupStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new AdminsGroup(), $options);
    }
}
