<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Ec2\CacheSecurityGroup;
use Codinglabs\Yolo\Steps\Sync\App\AuthoriseCacheIngressStep;

/**
 * Provisions the env-owned Valkey cache security group — the group itself only.
 * Each consuming app authorises its own task-SG ingress on 6379 separately
 * ({@see AuthoriseCacheIngressStep}), the same
 * env-SG / app-ingress split Typesense uses. Bootstrapped from sync:app, gated on
 * the app opting into the cache (`cache.store: redis`); created-if-missing.
 */
class SyncCacheSecurityGroupStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (Manifest::cacheStore() !== 'redis') {
            return StepResult::SKIPPED;
        }

        return $this->syncResource(new CacheSecurityGroup(), $options);
    }
}
