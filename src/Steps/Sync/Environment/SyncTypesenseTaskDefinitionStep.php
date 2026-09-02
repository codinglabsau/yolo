<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Contracts\SkippedByDeployCheck;
use Codinglabs\Yolo\Services\TypesenseTaskDefinition;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * One family for every node: the image bakes the full peer list and each node
 * identifies itself by matching a local interface. Unlike app task definitions
 * (where the image is deploy's call), sync owns the image here — a version bump
 * or key rotation registers a new revision that the nodes step rolls through
 * the cluster in the same sync, which is why that step shares
 * TypesenseTaskDefinition's desired-vs-live check rather than trusting the live latest.
 *
 * Teardown is a skip — revisions are registration history, not standing infrastructure.
 */
class SyncTypesenseTaskDefinitionStep implements SkippedByDeployCheck, Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        if (Lifecycle::state(Service::TYPESENSE) !== ServiceState::Provision) {
            return StepResult::SKIPPED;
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');
        $live = TypesenseTaskDefinition::live();

        try {
            $desired = TypesenseTaskDefinition::desired();
        } catch (ResourceDoesNotExistException) {
            // The execution role / image tag aren't resolvable on a greenfield plan
            // pass — report pending; on apply they exist, so a genuine miss is a hard fail.
            if ($dryRun) {
                $this->recordChange(Change::make('typesense task definition', 'absent', 'new revision'));

                return StepResult::WOULD_SYNC;
            }

            throw new ResourceDoesNotExistException('Cannot render the Typesense task definition — the execution role, image and log group must exist by now.');
        }

        if ($live !== null && TypesenseTaskDefinition::matches($desired, $live)) {
            return StepResult::SYNCED;
        }

        $this->recordChange(Change::make(
            'typesense task definition',
            $live === null ? 'absent' : 'revision ' . ($live['revision'] ?? '?'),
            'new revision',
        ));

        if ($dryRun) {
            return StepResult::WOULD_SYNC;
        }

        Aws::ecs()->registerTaskDefinition($desired);

        return StepResult::SYNCED;
    }
}
