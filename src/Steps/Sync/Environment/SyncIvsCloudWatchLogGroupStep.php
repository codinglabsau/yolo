<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\CloudWatchLogs\IvsLogGroup;

/**
 * The env-shared IVS event log group, gated on the service lifecycle:
 * provisioned while the environment manifest declares `services.ivs`, torn down
 * when the declaration is removed. One pipeline per environment — the `aws.ivs`
 * event stream is account-wide, so this was never a per-app resource.
 */
class SyncIvsCloudWatchLogGroupStep implements Step
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
