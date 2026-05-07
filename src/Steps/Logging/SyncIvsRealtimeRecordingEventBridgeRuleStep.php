<?php

namespace Codinglabs\Yolo\Steps\Logging;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncIvsRealtimeRecordingEventBridgeRuleStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (! Manifest::ivsEnabled()) {
            return StepResult::SKIPPED;
        }

        if (! Manifest::get('aws.ivs.recording_webhook_url')) {
            return StepResult::SKIPPED;
        }

        if (! Manifest::get('aws.ivs.recording_webhook_secret')) {
            return StepResult::SKIPPED;
        }

        $name = self::ruleName();

        try {
            AwsResources::eventBridgeRule($name);

            if (! Arr::get($options, 'dry-run')) {
                Aws::eventBridge()->putRule([
                    'Name' => $name,
                    'Description' => 'YOLO managed IVS Real-Time participant recording state change events',
                    'EventPattern' => json_encode(self::eventPattern()),
                    'State' => 'ENABLED',
                    ...Aws::tags([
                        'Name' => $name,
                    ]),
                ]);

                return StepResult::SYNCED;
            }

            return StepResult::WOULD_SYNC;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::eventBridge()->putRule([
                    'Name' => $name,
                    'Description' => 'YOLO managed IVS Real-Time participant recording state change events',
                    'EventPattern' => json_encode(self::eventPattern()),
                    'State' => 'ENABLED',
                    ...Aws::tags([
                        'Name' => $name,
                    ]),
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }

    public static function ruleName(): string
    {
        return Helpers::keyedResourceName('ivs-participant-recording-state-change');
    }

    public static function eventPattern(): array
    {
        return [
            'source' => ['aws.ivs'],
            'detail-type' => ['IVS Participant Recording State Change'],
            'detail' => [
                'event_name' => ['Recording End'],
            ],
        ];
    }
}
