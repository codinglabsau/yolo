<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\ElbV2\MeilisearchTargetGroup;

/**
 * Provisions the shared Meilisearch target group when the app opts into search
 * (`scout.driver: meilisearch`).
 */
class SyncMeilisearchTargetGroupStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (Manifest::scoutDriver() !== 'meilisearch') {
            return StepResult::SKIPPED;
        }

        return $this->syncResource(new MeilisearchTargetGroup(), $options);
    }
}
