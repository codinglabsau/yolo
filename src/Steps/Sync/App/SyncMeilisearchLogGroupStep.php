<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\CloudWatchLogs\MeilisearchLogGroup;

/**
 * Provisions the shared Meilisearch log group when the app opts into search
 * (`scout.driver: meilisearch`).
 */
class SyncMeilisearchLogGroupStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (Manifest::scoutDriver() !== 'meilisearch') {
            return StepResult::SKIPPED;
        }

        return $this->syncResource(new MeilisearchLogGroup(), $options);
    }
}
