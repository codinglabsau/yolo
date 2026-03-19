<?php

namespace Codinglabs\Yolo\Steps\Ivs;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Aws\EventBridge\Exception\EventBridgeException;

class SyncEventBridgeTargetStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (! Manifest::isIvsSupported()) {
            return StepResult::SKIPPED;
        }

        $ruleName = SyncEventBridgeRuleStep::ruleName();
        $logGroupName = SyncCloudWatchLogGroupStep::logGroupName();

        try {
            $targets = Aws::eventBridge()->listTargetsByRule([
                'Rule' => $ruleName,
            ]);
        } catch (EventBridgeException $e) {
            return Arr::get($options, 'dry-run')
                ? StepResult::WOULD_CREATE
                : StepResult::CREATED;
        }

        $targetExists = collect($targets['Targets'])->contains(
            fn ($target) => $target['Id'] === 'ivs-cloudwatch-logs'
        );

        if ($targetExists) {
            return StepResult::SYNCED;
        }

        if (! Arr::get($options, 'dry-run')) {
            $region = Manifest::get('aws.region');
            $accountId = Aws::accountId();
            $logGroupArn = "arn:aws:logs:{$region}:{$accountId}:log-group:{$logGroupName}";

            Aws::eventBridge()->putTargets([
                'Rule' => $ruleName,
                'Targets' => [
                    [
                        'Id' => 'ivs-cloudwatch-logs',
                        'Arn' => $logGroupArn,
                    ],
                ],
            ]);

            return StepResult::CREATED;
        }

        return StepResult::WOULD_CREATE;
    }
}
