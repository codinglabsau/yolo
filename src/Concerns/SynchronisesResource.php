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
 * Generic create-or-sync orchestration for steps backed by a Resource.
 * Steps with extra gating (cert state, manifest predicates, ingress rules)
 * keep their orchestration but delegate identity / create / tag-sync here.
 *
 * Both kinds of drift — tags AND attribute config — surface symmetrically:
 * each is computed against the live resource regardless of --dry-run (with
 * apply=false so nothing is written), and any missing key / drifted attribute
 * is recorded as a Change so the plan→apply orchestrator (`SyncSteppedCommand`)
 * can list exactly what would change, and so the apply pass survives the
 * "only-pending-steps" filter (a step with tag drift but no
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
     * Tag-sync an existing resource, refusing to adopt a stranger. A live
     * resource that matches by name but carries no `yolo:scope` ownership
     * marker was not created by YOLO — most dangerously it belongs to another
     * deployment tool or an earlier YOLO generation sharing the account, and
     * stamping YOLO tags on it would claim live infrastructure that isn't
     * ours (and put it in teardown's sights). The guard runs before any
     * write, on the plan and apply passes alike, so the sync fails loudly at
     * plan time instead of silently defacing the resource. Resources marked
     * {@see Adoptable} (the hosted zone, the GitHub OIDC provider — account
     * singletons that legitimately pre-exist) are exempt.
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
        // Belt-and-braces: a resource marked Undeletable (the BYO app data bucket)
        // must never reach a delete(). It isn't Deletable, so it can't be typed in
        // here — but a future class that wrongly implemented both would be caught,
        // not silently torn down. (tests/Arch/UndeletableTest.php forbids both.)
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
}
