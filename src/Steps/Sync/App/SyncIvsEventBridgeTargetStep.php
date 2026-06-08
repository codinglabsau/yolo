<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\EventBridge;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesIvsStep;
use Codinglabs\Yolo\Resources\CloudWatchLogs\IvsLogGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Resources\EventBridge\IvsEventBridgeRule;

class SyncIvsEventBridgeTargetStep implements ExecutesIvsStep
{
    public function __invoke(array $options): StepResult
    {
        $ruleName = (new IvsEventBridgeRule())->name();
        $expectedArn = (new IvsLogGroup())->arn();

        $existingTarget = null;

        try {
            EventBridge::rule($ruleName);

            $existingTarget = collect(Aws::eventBridge()->listTargetsByRule([
                'Rule' => $ruleName,
            ])['Targets'])->first(fn ($target): bool => $target['Id'] === 'ivs-cloudwatch-logs');

            if ($existingTarget && $existingTarget['Arn'] === $expectedArn) {
                return StepResult::SYNCED;
            }
        } catch (ResourceDoesNotExistException) {
            // Rule doesn't exist yet — target needs to be created.
        }

        if (! Arr::get($options, 'dry-run')) {
            Aws::eventBridge()->putTargets([
                'Rule' => $ruleName,
                'Targets' => [
                    ['Id' => 'ivs-cloudwatch-logs', 'Arn' => $expectedArn],
                ],
            ]);

            return $existingTarget ? StepResult::SYNCED : StepResult::CREATED;
        }

        return $existingTarget ? StepResult::WOULD_SYNC : StepResult::WOULD_CREATE;
    }
}
