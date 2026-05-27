<?php

namespace Codinglabs\Yolo\Steps\Logging;

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesIvsStep;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\CloudWatchLogs\IvsLogGroup;

class SyncIvsCloudWatchLogGroupStep implements ExecutesIvsStep
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return $this->syncResource(new IvsLogGroup(), $options);
    }
}
