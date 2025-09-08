<?php

namespace Codinglabs\Yolo\Steps\Stop\Web;

use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\RunsOnAwsWeb;
use Codinglabs\Yolo\Concerns\InteractsWithNginx;
use Codinglabs\Yolo\Concerns\InteractsWithSupervisor;

class StopWorkOnWebStep implements RunsOnAwsWeb
{
    use InteractsWithNginx;
    use InteractsWithSupervisor;

    public function __invoke(): StepResult
    {
        $this->stopSupervisorWorkers();
        $this->stopNginx();

        return StepResult::SUCCESS;
    }
}
