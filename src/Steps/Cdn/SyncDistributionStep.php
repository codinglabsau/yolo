<?php

namespace Codinglabs\Yolo\Steps\Cdn;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Cdn\Distribution;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncDistributionStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new Distribution(), $options);
    }
}
