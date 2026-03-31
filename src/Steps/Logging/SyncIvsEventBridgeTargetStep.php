<?php

namespace Codinglabs\Yolo\Steps\Logging;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncIvsEventBridgeTargetStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (! Manifest::get('aws.logging.ivs')) {
            return StepResult::SKIPPED;
        }

        $ruleName = SyncIvsEventBridgeRuleStep::ruleName();
        $logGroupName = SyncIvsCloudWatchLogGroupStep::logGroupName();

        $region = Manifest::get('aws.region');
        $accountId = Aws::accountId();
        $expectedArn = "arn:aws:logs:{$region}:{$accountId}:log-group:{$logGroupName}";

        $existingTarget = null;

        try {
            $targets = AwsResources::eventBridgeRuleTargets($ruleName);

            $existingTarget = collect($targets)->first(
                fn ($target) => $target['Id'] === 'ivs-cloudwatch-logs'
            );

            if ($existingTarget && $existingTarget['Arn'] === $expectedArn) {
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
                        'Id' => 'ivs-cloudwatch-logs',
                        'Arn' => $expectedArn,
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
