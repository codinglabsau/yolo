<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Contracts\SkippedByDeployCheck;
use Codinglabs\Yolo\Resources\CloudWatchLogs\IvsLogGroup;

/** One pipeline per environment — the `aws.ivs` event stream is account-wide, never per-app. */
class SyncIvsCloudWatchLogGroupStep implements SkippedByDeployCheck, Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return match (Lifecycle::state(Service::IVS)) {
            ServiceState::Provision => $this->syncResource(new IvsLogGroup(), $options),
            ServiceState::Teardown => $this->teardownResource(new IvsLogGroup(), $options),
        };
    }
}
