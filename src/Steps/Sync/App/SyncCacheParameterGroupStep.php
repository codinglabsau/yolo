<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\ElastiCache\CacheParameterGroup;

/**
 * Provisions the custom cache parameter group (`maxmemory-policy=allkeys-lru`)
 * when the app opts into the shared Valkey cache (`aws.cache`).
 */
class SyncCacheParameterGroupStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::has('aws.cache')) {
            return StepResult::SKIPPED;
        }

        return $this->syncResource(new CacheParameterGroup(), $options);
    }
}
