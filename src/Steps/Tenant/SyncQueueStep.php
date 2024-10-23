<?php

namespace Codinglabs\Yolo\Steps\Tenant;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\TenantStep;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncQueueStep extends TenantStep
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::queue($this->tenantId());
            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::sqs()->createQueue([
                    'QueueName' => $this->tenantId(),
                    'Attributes' => [
                        'MessageRetentionPeriod' => '1209600', // 14 days
                    ],
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
