<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncEcsClusterStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new EcsCluster(), $options);
    }
}
