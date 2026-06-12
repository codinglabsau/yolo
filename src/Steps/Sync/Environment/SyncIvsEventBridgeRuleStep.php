<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\EnvManifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\EventBridge\IvsEventBridgeRule;

/**
 * Provisions the env-shared IVS state-change EventBridge rule when the
 * environment manifest declares the ivs service (`services.ivs`).
 */
class SyncIvsEventBridgeRuleStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (! EnvManifest::has('services.ivs')) {
            return StepResult::SKIPPED;
        }

        return $this->syncResource(new IvsEventBridgeRule(), $options);
    }
}
