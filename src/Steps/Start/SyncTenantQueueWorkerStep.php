<?php

namespace Codinglabs\Yolo\Steps\Start;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Contracts\RunsOnAwsQueue;

class SyncTenantQueueWorkerStep extends TenantStep implements RunsOnAwsQueue
{
    public function __invoke(array $options): StepResult
    {
        file_put_contents(
            "/etc/supervisor/conf.d/{$this->tenantId()}-queue-worker.conf",
            str_replace(
                search: [
                    '{NAME}',
                    '{TENANT}',
                    '{AWS_SQS_ENDPOINT}',
                ],
                replace: [
                    Manifest::name(),
                    $this->tenantId(),
                    AwsResources::queue($this->tenantId())['QueueUrl'],
                ],
                subject: file_get_contents(Paths::stubs('supervisor/tenant-queue-worker.conf.stub'))
            )
        );

        return StepResult::SYNCED;
    }
}
