<?php

namespace Codinglabs\Yolo\Steps\Start\Web;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\RunsOnAwsWeb;

class SyncOctaneWorkerStep implements RunsOnAwsWeb
{
    public function __invoke(array $options): StepResult
    {
        $file = sprintf('/etc/supervisor/conf.d/%s', Helpers::keyedResourceName('octane-worker.conf'));

        if (! Manifest::get('aws.ec2.octane')) {
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
                subject: file_get_contents(Paths::stubs('supervisor/octane-worker.conf.stub'))
            )
        );

        return StepResult::SYNCED;
    }
}
