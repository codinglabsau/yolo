<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Ecs\MeilisearchService;

/**
 * Provisions the shared Meilisearch service when the app opts into search
 * (`scout.driver: meilisearch`). Depends on the master key, execution role,
 * log group, security group, target group and services cluster, so it runs
 * last in the Meilisearch sequence. Created once, then frozen — sync
 * reconciles tags only, so apps pinned to different YOLO versions can never
 * thrash the shared instance (the CacheCluster precedent).
 */
class SyncMeilisearchServiceStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (Manifest::scoutDriver() !== 'meilisearch') {
            return StepResult::SKIPPED;
        }

        return $this->syncResource(new MeilisearchService(), $options);
    }
}
