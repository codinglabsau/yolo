<?php

namespace Codinglabs\Yolo\Steps\Stop\Queue;

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\RunsOnAwsQueue;
use Codinglabs\Yolo\Concerns\InteractsWithSupervisor;

class StopWorkOnQueueStep implements RunsOnAwsQueue
{
    use InteractsWithSupervisor;

    public function __invoke(): StepResult
    {
        $this->stopSupervisorWorkers();

        return StepResult::SUCCESS;
    }
}
