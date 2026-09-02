<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\EcsTaskPolicy;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncEcsTaskPolicyStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new EcsTaskPolicy(), $options);
    }
}
