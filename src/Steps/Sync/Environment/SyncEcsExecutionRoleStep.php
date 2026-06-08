<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Iam\EcsExecutionRole;

class SyncEcsExecutionRoleStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        // Trust-policy drift rides through SynchronisesConfiguration on the role.
        return $this->syncResource(new EcsExecutionRole(), $options);
    }
}
