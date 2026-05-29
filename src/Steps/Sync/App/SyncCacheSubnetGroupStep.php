<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\ElastiCache\CacheSubnetGroup;

/**
 * Provisions the ElastiCache subnet group when the app opts into the shared
 * Valkey cache (`aws.cache`). Env-scoped resource bootstrapped from sync:app,
 * created-if-missing and never mutated — mirrors the RDS-SG exception pattern.
 */
class SyncCacheSubnetGroupStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::has('aws.cache')) {
            return StepResult::SKIPPED;
        }

        return $this->syncResource(new CacheSubnetGroup(), $options);
    }
}
