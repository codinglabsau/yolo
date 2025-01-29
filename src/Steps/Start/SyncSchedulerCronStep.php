<?php

namespace Codinglabs\Yolo\Steps\Start;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Contracts\RunsOnAwsScheduler;

class SyncSchedulerCronStep implements RunsOnAwsScheduler
{
    public function __invoke(array $options): StepResult
    {
        file_put_contents(
            sprintf('/etc/cron.d/%s', Helpers::keyedResourceName(ServerGroup::SCHEDULER)),
            str_replace(
                search: [
                    '{NAME}',
                ],
                replace: [
                    Manifest::name(),
                ],
                subject: file_get_contents(Paths::stubs('cron/scheduler.stub'))
            )
        );

        return StepResult::SYNCED;
    }
}
