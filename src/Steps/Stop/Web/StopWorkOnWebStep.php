<?php

namespace Codinglabs\Yolo\Steps\Stop\Web;

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\RunsOnAwsWeb;
use Codinglabs\Yolo\Concerns\InteractsWithSupervisor;

class StopWorkOnWebStep implements RunsOnAwsWeb
{
    use InteractsWithSupervisor;

    public function __invoke(): StepResult
    {
        // stop pulse, octane workers
        $this->stopSupervisorWorkers();

        return StepResult::SUCCESS;
    }
}
