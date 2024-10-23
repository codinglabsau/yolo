<?php

namespace Codinglabs\Yolo\Steps\Start;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\RunsOnAws;

class SyncLogrotateStep implements RunsOnAws
{
    public function __invoke(): StepResult
    {
        file_put_contents(
            "/etc/logrotate.d/laravel",
            str_replace(
                search: [
                    '{NAME}',
                ],
                replace: [
                    Manifest::name(),
                ],
                subject: file_get_contents(Paths::stubs('logrotate/laravel.stub'))
            )
        );

        return StepResult::SYNCED;
    }
}
