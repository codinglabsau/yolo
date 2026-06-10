<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Iam\MeilisearchExecutionRole;

/**
 * Provisions the Meilisearch execution role (log writes + master-key read for
 * the task definition's `secrets`) when the app opts into search
 * (`scout.driver: meilisearch`).
 */
class SyncMeilisearchExecutionRoleStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (Manifest::scoutDriver() !== 'meilisearch') {
            return StepResult::SKIPPED;
        }

        return $this->syncResource(new MeilisearchExecutionRole(), $options);
    }
}
