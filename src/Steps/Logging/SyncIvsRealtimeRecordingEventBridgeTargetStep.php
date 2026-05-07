<?php

namespace Codinglabs\Yolo\Steps\Logging;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Aws\EventBridge\Exception\EventBridgeException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncIvsRealtimeRecordingEventBridgeTargetStep implements Step
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

        $ruleName = SyncIvsRealtimeRecordingEventBridgeRuleStep::ruleName();
        $destinationName = Helpers::keyedResourceName('ivs-recording-webhook-destination');

        try {
            $destination = Aws::eventBridge()->describeApiDestination(['Name' => $destinationName]);
            $destinationArn = $destination['ApiDestinationArn'];
        } catch (EventBridgeException) {
            return StepResult::WOULD_CREATE;
        }

        $existingTarget = null;

        try {
            AwsResources::eventBridgeRule($ruleName);

            $existingTarget = collect(Aws::eventBridge()->listTargetsByRule([
                'Rule' => $ruleName,
            ])['Targets'])->first(
                fn ($target) => $target['Id'] === 'ivs-recording-webhook'
            );

            if ($existingTarget && $existingTarget['Arn'] === $destinationArn) {
                return StepResult::SYNCED;
            }
        } catch (ResourceDoesNotExistException) {
            // Rule doesn't exist yet — target needs to be created
        }

        if (! Arr::get($options, 'dry-run')) {
            Aws::eventBridge()->putTargets([
                'Rule' => $ruleName,
                'Targets' => [
                    [
                        'Id' => 'ivs-recording-webhook',
                        'Arn' => $destinationArn,
                        'HttpParameters' => [
                            'HeaderParameters' => [],
                            'QueryStringParameters' => [],
                        ],
                    ],
                ],
            ]);

            return $existingTarget
                ? StepResult::SYNCED
                : StepResult::CREATED;
        }

        return $existingTarget
            ? StepResult::WOULD_SYNC
            : StepResult::WOULD_CREATE;
    }
}
