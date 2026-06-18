<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

/**
 * Generic create-or-sync orchestration for steps backed by a Resource.
 * Steps with extra gating (cert state, manifest predicates, ingress rules)
 * keep their orchestration but delegate identity / create / tag-sync here.
 *
 * Both kinds of drift — tags AND attribute config — surface symmetrically:
 * each is computed against the live resource regardless of --dry-run (with
 * apply=false so nothing is written), and any missing key / drifted attribute
 * is recorded as a Change so the plan→apply orchestrator (`SyncSteppedCommand`)
 * can list exactly what would change, and so the apply pass survives the
 * "only-pending-steps" filter from PR #57 (a step with tag drift but no
 * config drift was silently dropped before, so the plan-clean SYNCED meant
 * the apply never ran the tag write).
 */
trait SynchronisesResource
{
    use RecordsChanges;

    protected function syncResource(Resource $resource, array $options): StepResult
    {
        $dryRun = (bool) Arr::get($options, 'dry-run');

        if ($resource->exists()) {
            $hasChanges = false;

            $missingTags = $resource->synchroniseTags(apply: ! $dryRun);

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
     * The teardown mirror of syncResource(): the shared teardown engine for
     * both the env-service lifecycle (the environment no longer runs the
     * service) AND `yolo destroy:app` (tearing an app down via TeardownStep). A
     * resource that exists is recorded and deleted (WOULD_DELETE on the plan
     * pass); absent already ⇒ SKIPPED, so a torn-down resource stays quiet on
     * every later sync. The Change is recorded before the dry-run guard so the
     * plan and apply passes agree and the step survives the pending-only prune.
     */
    protected function teardownResource(Resource&Deletable $resource, array $options): StepResult
    {
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
}
