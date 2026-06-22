<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\ElastiCache\CacheSubnetGroup;

/**
 * Provisions the ElastiCache subnet group when the app opts into the shared
 * Valkey cache (`cache.store: redis`). Env-scoped resource bootstrapped from
 * sync:app, created-if-missing and never mutated — mirrors the RDS-SG exception.
 */
class SyncCacheSubnetGroupStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (Manifest::cacheStore() !== 'redis') {
            return StepResult::SKIPPED;
        }

        return $this->syncResource(new CacheSubnetGroup(), $options);
    }
}
