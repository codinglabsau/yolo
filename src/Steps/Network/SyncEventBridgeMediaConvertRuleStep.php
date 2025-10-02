<?php

namespace Codinglabs\Yolo\Steps\Network;

use Exception;
use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\EventBridge;

class SyncEventBridgeMediaConvertRuleStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (! Manifest::get('aws.mediaconvert')) {
            return StepResult::SKIPPED;
        }

        try {
            AwsResources::eventBridgeMediaConvertRule();

            return StepResult::SYNCED;
        } catch (Exception $e) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::eventBridge()->putRule([
                    'Name' => Helpers::keyedResourceName(EventBridge::MEDIA_CONVERT_RULE),
                    'EventPattern' => json_encode([
                        'source' => ['aws.mediaconvert'],
                    ]),
                    'State' => 'ENABLED',
                    'Description' => 'Listen to all updates from MediaConvert',
                    ...Aws::tags(),
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
