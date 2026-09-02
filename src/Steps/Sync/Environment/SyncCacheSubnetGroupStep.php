<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\ElastiCache\CacheSubnetGroup;

/** Env-scoped but bootstrapped from sync:app, created-if-missing and never mutated. */
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
