<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Adoptable;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\Undeletable;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

/**
 * Tag drift and attribute drift are both computed on the plan pass and recorded
 * as Changes — a step with only tag drift must still survive the pending-only
 * prune, or apply never runs the tag write.
 */
trait SynchronisesResource
{
    use RecordsChanges;

    protected function syncResource(Resource $resource, array $options): StepResult
    {
        $dryRun = (bool) Arr::get($options, 'dry-run');

        if ($resource->exists()) {
            $hasChanges = false;

            $missingTags = $this->synchroniseOwnedTags($resource, $dryRun);

            foreach ($missingTags as $key => $value) {
                $this->recordChange(Change::make("tag {$key}", null, $value));
                $hasChanges = true;
            }

            if ($resource instanceof SynchronisesConfiguration) {
                $configChanges = $resource->synchroniseConfiguration(apply: ! $dryRun);
                $this->recordChanges($configChanges);
                $hasChanges = $hasChanges || $configChanges !== [];
            }

            if ($hasChanges) {
                return $dryRun ? StepResult::WOULD_SYNC : StepResult::SYNCED;
            }

            return StepResult::SYNCED;
        }

        if ($dryRun) {
            return StepResult::WOULD_CREATE;
        }

        $resource->create();

        return StepResult::CREATED;
    }

    /**
     * A name-matched resource with no `yolo:scope` marker was not created by YOLO —
     * stamping tags on it would claim another tool's infrastructure and put it in
     * teardown's sights, so refuse before any write. {@see Adoptable} account
     * singletons that legitimately pre-exist are exempt.
     *
     * @return array<string, string> the missing tags, as synchroniseTags() reports them
     */
    protected function synchroniseOwnedTags(Resource $resource, bool $dryRun): array
    {
        $missingTags = $resource->synchroniseTags(apply: false);

        if (! $resource instanceof Adoptable && array_key_exists('yolo:scope', $missingTags)) {
            throw new IntegrityCheckException(sprintf(
                'Refusing to adopt "%s": it already exists but does not carry the yolo:scope ownership tag, ' .
                'so it was not created by YOLO — it may belong to another deployment tool sharing this account. ' .
                'Remove or rename the conflicting resource, or tag it with yolo:scope=%s manually if it is genuinely YOLO-managed, then re-run the sync.',
                $resource->name(),
                $resource->scope()->value,
            ));
        }

        if (! $dryRun) {
            $resource->synchroniseTags(apply: true);
        }

        return $missingTags;
    }

    /**
     * The Change is recorded before the dry-run guard so the plan and apply
     * passes agree and the step survives the pending-only prune.
     */
    protected function teardownResource(Resource&Deletable $resource, array $options): StepResult
    {
        // Undeletable isn't Deletable so can't be typed in here, but a class that
        // wrongly implemented both must be caught, not silently torn down.
        if ($resource instanceof Undeletable) {
            throw new IntegrityCheckException(sprintf(
                'Refusing to tear down "%s": it is marked Undeletable and must never be deleted.',
                $resource->name(),
            ));
        }

        if (! $resource->exists()) {
            return StepResult::SKIPPED;
        }

        $this->recordChange(Change::make($resource->name(), 'provisioned', null));

        if ((bool) Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_DELETE;
        }

        $resource->delete();

        return StepResult::DELETED;
    }

    /**
     * @param  array<int, StepResult>  $results
     */
    protected function aggregateResults(array $results): StepResult
    {
        foreach ([
            StepResult::WOULD_CREATE, StepResult::CREATED,
            StepResult::WOULD_DELETE, StepResult::DELETED,
            StepResult::WOULD_SYNC,
        ] as $significant) {
            if (in_array($significant, $results, true)) {
                return $significant;
            }
        }

        return in_array(StepResult::SYNCED, $results, true) ? StepResult::SYNCED : StepResult::SKIPPED;
    }
}
