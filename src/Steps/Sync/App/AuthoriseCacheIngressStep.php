<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\AuthorisesTaskIngress;
use Codinglabs\Yolo\Resources\Ec2\CacheSecurityGroup;
use Codinglabs\Yolo\Resources\ElastiCache\CacheCluster;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Steps\Destroy\App\RevokeCacheIngressStep;

/**
 * This app's private path to the shared Valkey cache: an additive 6379-from-task-SG
 * ingress rule on the (env-owned) cache security group — the app-level half of the
 * cache, the mirror of {@see RevokeCacheIngressStep}.
 * Runs after SyncTaskSecurityGroupStep (the ingress source) and skips with
 * instructions while the cache SG isn't bootstrapped yet. Mirrors
 * SyncTypesenseAppIngressStep.
 */
class AuthoriseCacheIngressStep implements Step
{
    use AuthorisesTaskIngress;

    public function __invoke(array $options): StepResult
    {
        if (Manifest::cacheStore() !== 'redis') {
            return StepResult::SKIPPED;
        }

        try {
            $groupId = (new CacheSecurityGroup())->arn();
        } catch (ResourceDoesNotExistException) {
            // The cache SG isn't provisioned yet — the rule lands on the next pass.
            return StepResult::SKIPPED;
        }

        $changed = $this->reconcileTaskIngressRule(
            $groupId,
            CacheCluster::PORT,
            'Enable Fargate tasks to connect to the Valkey cache',
            (bool) Arr::get($options, 'dry-run'),
        );

        if (! $changed) {
            return StepResult::SYNCED;
        }

        return Arr::get($options, 'dry-run') ? StepResult::WOULD_SYNC : StepResult::SYNCED;
    }
}
