<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\ObserversGroup;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

/** YOLO owns the group + its assume-role policy; membership is the human lever, never managed here. */
class SyncObserversGroupStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new ObserversGroup(), $options);
    }
}
