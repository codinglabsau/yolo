<?php

namespace Codinglabs\Yolo\Steps\Start;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\RunsOnAwsQueue;

class SyncLandlordQueueWorkerStep implements RunsOnAwsQueue
{
    public function __invoke(): StepResult
    {
        file_put_contents(
            "/etc/supervisor/conf.d/landlord-queue-worker.conf",
            str_replace(
                search: [
                    '{NAME}',
                    '{AWS_SQS_ENDPOINT}',
                ],
                replace: [
                    Manifest::name(),
                    AwsResources::queue('landlord')['QueueUrl'],
                ],
                subject: file_get_contents(Paths::stubs('supervisor/landlord-queue-worker.conf.stub'))
            )
        );

        return StepResult::SYNCED;
    }
}
