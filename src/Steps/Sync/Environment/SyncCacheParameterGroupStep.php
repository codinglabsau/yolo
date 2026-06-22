<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\ElastiCache\CacheParameterGroup;

/**
 * Provisions the custom cache parameter group (`maxmemory-policy=allkeys-lru`)
 * when the app opts into the shared Valkey cache (`cache.store: redis`).
 */
class SyncCacheParameterGroupStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (Manifest::cacheStore() !== 'redis') {
            return StepResult::SKIPPED;
        }

        return $this->syncResource(new CacheParameterGroup(), $options);
    }
}
