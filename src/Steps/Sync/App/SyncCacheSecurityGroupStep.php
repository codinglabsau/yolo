<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Concerns\AuthorisesTaskIngress;
use Codinglabs\Yolo\Resources\Ec2\CacheSecurityGroup;
use Codinglabs\Yolo\Resources\ElastiCache\CacheCluster;

/**
 * Provisions the Valkey cache security group and authorises the Fargate tasks
 * to reach the cache on 6379. Runs in sync:app (after SyncTaskSecurityGroupStep)
 * because the ingress source is the ECS task SG, which sync:app creates.
 *
 * The ingress rule is managed purely additively (see AuthorisesTaskIngress).
 * Mirrors SyncRdsSecurityGroupStep.
 */
class SyncCacheSecurityGroupStep implements Step
{
    use AuthorisesTaskIngress;
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (Manifest::cacheStore() !== 'redis') {
            return StepResult::SKIPPED;
        }

        $securityGroup = new CacheSecurityGroup();

        $dryRun = (bool) Arr::get($options, 'dry-run');
        $result = $this->syncResource($securityGroup, $options);

        $description = 'Enable Fargate tasks to connect to the Valkey cache';

        if ($securityGroup->exists() && $this->reconcileTaskIngressRule($securityGroup->arn(), CacheCluster::PORT, $description, $dryRun) && $dryRun && $result === StepResult::SYNCED) {
            // The group already exists but the ingress rule is missing, so a
            // dry-run has a pending change to report rather than a clean SYNCED.
            return StepResult::WOULD_SYNC;
        }

        return $result;
    }
}
