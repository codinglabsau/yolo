<?php

namespace Codinglabs\Yolo\Steps\Start;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\RunsOnAwsQueue;
use Codinglabs\Yolo\Contracts\ExecutesStandaloneStep;

class SyncQueueWorkerStep implements ExecutesStandaloneStep, RunsOnAwsQueue
{
    public function __invoke(): StepResult
    {
        file_put_contents(
            sprintf('/etc/supervisor/conf.d/%s', Helpers::keyedResourceName('queue-worker.conf')),
            str_replace(
                search: [
                    '{NAME}',
                    '{AWS_SQS_ENDPOINT}',
                ],
                replace: [
                    Manifest::name(),
                    AwsResources::queue(Helpers::keyedResourceName())['QueueUrl'],
                ],
                subject: file_get_contents(Paths::stubs('supervisor/landlord-queue-worker.conf.stub'))
            )
        );

        return StepResult::SYNCED;
    }
}
