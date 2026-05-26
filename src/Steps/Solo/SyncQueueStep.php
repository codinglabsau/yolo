<?php

namespace Codinglabs\Yolo\Steps\Solo;

use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Sqs\Queue;
use Codinglabs\Yolo\Contracts\ExecutesSoloStep;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

class SyncQueueStep implements ExecutesSoloStep, Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new Queue(Helpers::keyedResourceName()), $options);
    }
}
