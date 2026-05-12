<?php

namespace Codinglabs\Yolo\Steps\Recording;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncIvsRealtimeRecordingEventBridgeTargetStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (! Manifest::ivsRealtimeRemuxWebhookUrl()) {
            return StepResult::SKIPPED;
        }

        if (! Manifest::ivsWebhookSecret()) {
            return StepResult::SKIPPED;
        }

        if (! Manifest::ivsRealtimeMainBucket()) {
            return StepResult::SKIPPED;
        }

        $ruleName = SyncIvsRealtimeRecordingEventBridgeRuleStep::ruleName();
        $region = Manifest::get('aws.region');
        $accountId = Aws::accountId();
        $functionName = SyncIvsRemuxLambdaStep::functionName();
        $lambdaArn = "arn:aws:lambda:{$region}:{$accountId}:function:{$functionName}";
        $logGroupName = SyncIvsRecordingCloudWatchLogGroupStep::logGroupName();
        $logGroupArn = "arn:aws:logs:{$region}:{$accountId}:log-group:{$logGroupName}";

        $existingTarget = null;
        $hasOldWebhookTarget = false;

        try {
            AwsResources::eventBridgeRule($ruleName);

            $targets = collect(Aws::eventBridge()->listTargetsByRule([
                'Rule' => $ruleName,
            ])['Targets']);

            $existingTarget = $targets->first(fn ($t) => $t['Id'] === 'ivs-realtime-remux');
            $existingLogTarget = $targets->first(fn ($t) => $t['Id'] === 'ivs-recording-logs');
            $hasOldWebhookTarget = $targets->contains(fn ($t) => $t['Id'] === 'ivs-recording-webhook');

            if ($existingTarget && $existingTarget['Arn'] === $lambdaArn && $existingLogTarget) {
                if ($hasOldWebhookTarget && ! Arr::get($options, 'dry-run')) {
                    Aws::eventBridge()->removeTargets([
                        'Rule' => $ruleName,
                        'Ids' => ['ivs-recording-webhook'],
                    ]);
                }

                return StepResult::SYNCED;
            }
        } catch (ResourceDoesNotExistException) {
            // Rule doesn't exist yet — target needs to be created
        }

        if (! Arr::get($options, 'dry-run')) {
            if ($hasOldWebhookTarget) {
                Aws::eventBridge()->removeTargets([
                    'Rule' => $ruleName,
                    'Ids' => ['ivs-recording-webhook'],
                ]);
            }

            Aws::eventBridge()->putTargets([
                'Rule' => $ruleName,
                'Targets' => [
                    [
                        'Id' => 'ivs-realtime-remux',
                        'Arn' => $lambdaArn,
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
