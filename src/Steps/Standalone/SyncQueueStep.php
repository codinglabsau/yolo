<?php

namespace Codinglabs\Yolo\Steps\Standalone;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesStandaloneStep;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncQueueStep implements ExecutesStandaloneStep, Step
{
    public function __invoke(array $options): StepResult
    {
        $name = Helpers::keyedResourceName();

        try {
            AwsResources::queue($name);

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::sqs()->createQueue([
                    'QueueName' => $name,
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
