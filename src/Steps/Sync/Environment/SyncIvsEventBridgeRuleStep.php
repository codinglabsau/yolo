<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\EventBridge\IvsEventBridgeRule;

class SyncIvsEventBridgeRuleStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return match (Lifecycle::state(Service::IVS)) {
            ServiceState::Provision => $this->syncResource(new IvsEventBridgeRule(), $options),
            ServiceState::Teardown => $this->teardownResource(new IvsEventBridgeRule(), $options),
        };
    }
}
