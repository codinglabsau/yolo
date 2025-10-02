<?php

namespace Codinglabs\Yolo\Steps\Network;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Sns;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncSnsMediaConvertTopicStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (! Manifest::get('aws.mediaconvert')) {
            return StepResult::SKIPPED;
        }

        try {
            AwsResources::mediaConvertTopic();

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException $e) {
            $name = Helpers::keyedResourceName(Sns::MEDIA_CONVERT_TOPIC);

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
