<?php

namespace Codinglabs\Yolo\Steps\Recording;

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
        if (! Manifest::ivsRecordingWebhookUrl()) {
            return StepResult::SKIPPED;
        }

        if (! Manifest::ivsWebhookSecret()) {
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

            $targets = collect(Aws::eventBridge()->listTargetsByRule([
                'Rule' => $ruleName,
            ])['Targets']);

            $existingTarget = $targets->first(fn ($t) => $t['Id'] === 'ivs-recording-webhook');
            $existingLogTarget = $targets->first(fn ($t) => $t['Id'] === 'ivs-recording-logs');

            if ($existingTarget && $existingTarget['Arn'] === $destinationArn && $existingLogTarget) {
                return StepResult::SYNCED;
            }
        } catch (ResourceDoesNotExistException) {
            // Rule doesn't exist yet — target needs to be created
        }

        if (! Arr::get($options, 'dry-run')) {
            $roleArn = AwsResources::eventBridgeIvsRecordingRole()['Arn'];
            $region = Manifest::get('aws.region');
            $accountId = Aws::accountId();
            $logGroupName = SyncIvsRecordingCloudWatchLogGroupStep::logGroupName();
            $logGroupArn = "arn:aws:logs:{$region}:{$accountId}:log-group:{$logGroupName}";

            Aws::eventBridge()->putTargets([
                'Rule' => $ruleName,
                'Targets' => [
                    [
                        'Id' => 'ivs-recording-webhook',
                        'Arn' => $destinationArn,
                        'RoleArn' => $roleArn,
                        'HttpParameters' => [
                            'HeaderParameters' => [],
                            'QueryStringParameters' => [],
                        ],
                    ],
                    [
                        'Id' => 'ivs-recording-logs',
                        'Arn' => $logGroupArn,
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
