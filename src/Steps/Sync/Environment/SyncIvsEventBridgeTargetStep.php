<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\EnvManifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Aws\EventBridge;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\CloudWatchLogs\IvsLogGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Resources\EventBridge\IvsEventBridgeRule;

/**
 * Points the env-shared IVS state-change rule at the env-shared IVS log group
 * when the environment manifest declares the ivs service (`services.ivs`).
 */
class SyncIvsEventBridgeTargetStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (! EnvManifest::has('services.ivs')) {
            return StepResult::SKIPPED;
        }

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
