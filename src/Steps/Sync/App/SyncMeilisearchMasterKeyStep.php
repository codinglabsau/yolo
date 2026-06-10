<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Ssm\MeilisearchMasterKey;

/**
 * Generates the shared Meilisearch master key when the app opts into search
 * (`scout.driver: meilisearch`). Env-shared, bootstrapped from sync:app like
 * the Valkey cache — created if missing, never mutated.
 */
class SyncMeilisearchMasterKeyStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (Manifest::scoutDriver() !== 'meilisearch') {
            return StepResult::SKIPPED;
        }

        return $this->syncResource(new MeilisearchMasterKey(), $options);
    }
}
