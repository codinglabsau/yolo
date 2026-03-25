<?php

namespace Codinglabs\Yolo\Steps\Logging;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Aws\EventBridge\Exception\EventBridgeException;

class SyncIvsEventBridgeTargetStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (! Manifest::has('aws.ivs')) {
            return StepResult::SKIPPED;
        }

        $ruleName = SyncIvsEventBridgeRuleStep::ruleName();
        $logGroupName = SyncIvsCloudWatchLogGroupStep::logGroupName();

        $region = Manifest::get('aws.region');
        $accountId = Aws::accountId();
        $expectedArn = "arn:aws:logs:{$region}:{$accountId}:log-group:{$logGroupName}";

        try {
            $targets = Aws::eventBridge()->listTargetsByRule([
                'Rule' => $ruleName,
            ]);

            $existing = collect($targets['Targets'])->first(
                fn ($target) => $target['Id'] === 'ivs-cloudwatch-logs'
            );

            if ($existing && $existing['Arn'] === $expectedArn) {
                return StepResult::SYNCED;
            }
        } catch (EventBridgeException) {
            // Rule doesn't exist yet — target needs to be created
        }

        if (! Arr::get($options, 'dry-run')) {
            Aws::eventBridge()->putTargets([
                'Rule' => $ruleName,
                'Targets' => [
                    [
                        'Id' => 'ivs-cloudwatch-logs',
                        'Arn' => $expectedArn,
                    ],
                ],
            ]);

            return StepResult::CREATED;
        }

        return StepResult::WOULD_CREATE;
    }
}
