<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\ElastiCache\CacheCluster;

/**
 * Provisions the shared single-node Valkey replication group when the app opts
 * into the cache (`cache`). Depends on the cache subnet group, parameter
 * group and security group, so it runs last in the cache sequence.
 */
class SyncCacheClusterStep implements LongRunning
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (Manifest::cacheStore() !== 'redis') {
            return StepResult::SKIPPED;
        }

        return $this->syncResource(new CacheCluster(), $options);
    }

    public function patienceMessage(): string
    {
        return 'Provisioning the Valkey cache cluster — ElastiCache usually takes 5–15 minutes';
    }
}
