<?php

namespace Codinglabs\Yolo\Steps\Domain;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesDomainStep;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncQueueStep implements ExecutesDomainStep
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::queue(Helpers::keyedResourceName());
            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::sqs()->createQueue([
                    'QueueName' => Helpers::keyedResourceName(),
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
