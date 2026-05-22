<?php

namespace Codinglabs\Yolo\Steps\Fargate;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Fargate\EcrRepository;

class SyncEcrRepositoryStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new EcrRepository(), $options);
    }
}
