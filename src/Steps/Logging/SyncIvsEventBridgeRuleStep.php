<?php

namespace Codinglabs\Yolo\Steps\Logging;

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesIvsStep;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\EventBridge\IvsEventBridgeRule;

class SyncIvsEventBridgeRuleStep implements ExecutesIvsStep
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new IvsEventBridgeRule(), $options);
    }
}
