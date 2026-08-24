<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Contracts\SkippedByDeployCheck;
use Codinglabs\Yolo\Resources\CloudWatchLogs\WafLogGroup;

class SyncWafLogGroupStep implements ExecutesWebStep, SkippedByDeployCheck
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new WafLogGroup(), $options);
    }
}
