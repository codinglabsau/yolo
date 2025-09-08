<?php

namespace Codinglabs\Yolo\Steps\Landlord;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesMultitenancyStep;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncQueueStep implements ExecutesMultitenancyStep
{
    public function __invoke(array $options): StepResult
    {
        $name = Helpers::keyedResourceName('landlord');

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
                    ...Aws::tags(wrap: 'tags', associative: true),
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
