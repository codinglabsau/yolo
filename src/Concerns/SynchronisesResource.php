<?php

namespace Codinglabs\Yolo\Concerns;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

/**
 * Generic create-or-sync orchestration for steps backed by a Resource.
 * Steps with extra gating (cert state, manifest predicates, ingress rules)
 * keep their orchestration but delegate identity / create / tag-sync here.
 *
 * Config-drift detail is surfaced, not swallowed: for a resource that can drift
 * (SynchronisesConfiguration) the diff is computed even under --dry-run (with
 * apply=false, so nothing is written) and recorded so the runner can report
 * exactly which attributes changed. An existing-but-drifted resource therefore
 * reports WOULD_SYNC (dry-run) / SYNCED-with-changes (real run) instead of a
 * silent SYNCED that hid the writes.
 */
trait SynchronisesResource
{
    use RecordsChanges;

    protected function syncResource(Resource $resource, array $options): StepResult
    {
        $dryRun = (bool) Arr::get($options, 'dry-run');

        if ($resource->exists()) {
            if (! $dryRun) {
                $resource->synchroniseTags();
            }

            if ($resource instanceof SynchronisesConfiguration) {
                $changes = $resource->synchroniseConfiguration(apply: ! $dryRun);

                $this->recordChanges($changes);

                if ($changes !== []) {
                    return $dryRun ? StepResult::WOULD_SYNC : StepResult::SYNCED;
                }
            }

            return StepResult::SYNCED;
        }

        if ($dryRun) {
            return StepResult::WOULD_CREATE;
        }

        $resource->create();

        return StepResult::CREATED;
    }
}
