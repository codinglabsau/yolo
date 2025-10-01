<?php

namespace Codinglabs\Yolo\Steps\Network;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncSnsTopicStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::alarmTopic();

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException $e) {
            $name = Helpers::keyedResourceName(exclusive: false);

            if (! Arr::get($options, 'dry-run')) {
                Aws::sns()->createTopic([
                    'Name' => $name,
                    ...Aws::tags([
                        'Name' => $name,
                    ]),
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
