<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\EcsTaskRole;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncEcsTaskRoleStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        // Trust-policy drift rides through SynchronisesConfiguration on the role.
        return $this->syncResource(new EcsTaskRole(), $options);
    }
}
