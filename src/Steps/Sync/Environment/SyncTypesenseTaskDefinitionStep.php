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
 * The single task-definition family every Typesense node runs
 * (yolo-{env}-typesense) — one family, three services, because the image
 * bakes the full peer list and each node identifies itself by matching a
 * local interface. Unlike app task definitions (where the image is deploy's
 * call), sync owns the image here: the desired revision pins the current
 * content tag, so a version bump or key rotation registers a new revision and
 * the nodes step rolls it through the cluster one node at a time — in the
 * same sync, which is why the nodes step shares TypesenseTaskDefinition's
 * desired-vs-live check rather than trusting the live latest alone.
 *
 * Teardown is a skip — task-definition revisions are registration history,
 * not standing infrastructure (the audit ignores them for the same reason).
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
            // The execution role / image tag aren't resolvable yet (a
            // greenfield plan pass) — report pending; on apply the earlier
            // steps have provisioned them, so a genuine miss is a hard fail.
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
