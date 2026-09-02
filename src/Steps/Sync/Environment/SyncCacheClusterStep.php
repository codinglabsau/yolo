<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\ElastiCache\CacheCluster;

/** Runs last in the cache sequence — depends on the subnet, parameter and security groups. */
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
