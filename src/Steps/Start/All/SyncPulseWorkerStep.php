<?php

namespace Codinglabs\Yolo\Steps\Start\All;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\RunsOnAws;

class SyncPulseWorkerStep implements RunsOnAws
{
    public function __invoke(): StepResult
    {
        $file = sprintf('/etc/supervisor/conf.d/%s', Helpers::keyedResourceName('pulse-worker.conf'));

        if (! Manifest::get('pulse-worker', false)) {
            if (file_exists($file)) {
                unlink($file);
            }

            return StepResult::SKIPPED;
        }

        file_put_contents(
            $file,
            str_replace(
                search: [
                    '{NAME}',
                ],
                replace: [
                    Manifest::name(),
                ],
                subject: file_get_contents(Paths::stubs('supervisor/pulse-worker.conf.stub'))
            )
        );

        return StepResult::SYNCED;
    }
}
