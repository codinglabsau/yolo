<?php

namespace Codinglabs\Yolo\Steps\Start\All;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\RunsOnAws;

class SyncLogrotateStep implements RunsOnAws
{
    public function __invoke(): StepResult
    {
        file_put_contents(
            sprintf('/etc/logrotate.d/%s', Helpers::keyedResourceName()),
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

        file_put_contents(
            sprintf('/etc/logrotate.d/%s-yolo', Helpers::keyedResourceName()),
            str_replace(
                search: [
                    '{KEYED_RESOURCE_NAME}',
                ],
                replace: [
                    Helpers::keyedResourceName(),
                ],
                subject: file_get_contents(Paths::stubs('logrotate/yolo.stub'))
            )
        );

        return StepResult::SYNCED;
    }
}
