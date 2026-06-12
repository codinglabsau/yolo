<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Aws\EventBridge;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Resources\CloudWatchLogs\IvsLogGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Resources\EventBridge\IvsEventBridgeRule;

/**
 * Points the env-shared IVS state-change rule at the env-shared IVS log group
 * while the two-key lifecycle gate (offered ∧ claimed) holds. Teardown is
 * deliberately a skip: AWS refuses to delete a rule that still has targets,
 * so IvsEventBridgeRule::delete() removes the rule and this target in one
 * atomic act — a separate target-removal here would leave the plan's rule
 * deletion racing its own prerequisite across two steps.
 */
class SyncIvsEventBridgeTargetStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        $state = Lifecycle::state(Service::IVS);

        if ($state !== ServiceState::Provision) {
            return StepResult::SKIPPED;
        }

        $ruleName = (new IvsEventBridgeRule())->name();
        $expectedArn = (new IvsLogGroup())->arn();

        $existingTarget = null;

        try {
            EventBridge::rule($ruleName);

            $existingTarget = collect(Aws::eventBridge()->listTargetsByRule([
                'Rule' => $ruleName,
            ])['Targets'])->first(fn ($target): bool => $target['Id'] === IvsEventBridgeRule::TARGET_ID);

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
                    ['Id' => IvsEventBridgeRule::TARGET_ID, 'Arn' => $expectedArn],
                ],
            ]);

            return $existingTarget ? StepResult::SYNCED : StepResult::CREATED;
        }

        return $existingTarget ? StepResult::WOULD_SYNC : StepResult::WOULD_CREATE;
    }
}
