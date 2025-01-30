<?php

namespace Codinglabs\Yolo\Steps\Start\All;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\RunsOnAws;

class SyncHousekeepingCronStep implements RunsOnAws
{
    public function __invoke(array $options): StepResult
    {
        file_put_contents(
            "/etc/cron.d/yolo-housekeeping",
            file_get_contents(Paths::stubs('cron/housekeeping.stub'))
        );

        return StepResult::SYNCED;
    }
}
