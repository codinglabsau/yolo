<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\EventBridge\IvsEventBridgeRule;

/**
 * The env-shared IVS state-change EventBridge rule, gated on the service
 * lifecycle: provisioned while the environment manifest declares `services.ivs`,
 * torn down (rule + its log-group target in one act) when the declaration is
 * removed.
 */
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
