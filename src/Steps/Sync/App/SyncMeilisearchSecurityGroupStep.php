<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\AuthorisesIngress;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Ecs\MeilisearchService;
use Codinglabs\Yolo\Resources\Ec2\MeilisearchSecurityGroup;
use Codinglabs\Yolo\Resources\Ec2\LoadBalancerSecurityGroup;

/**
 * Provisions the Meilisearch security group and authorises the env load
 * balancer to reach the task on 7700 — the ALB is the sole ingress path, for
 * browser and server-side (Scout) traffic alike. The ingress rule is managed
 * purely additively (see AuthorisesIngress). Mirrors SyncCacheSecurityGroupStep.
 */
class SyncMeilisearchSecurityGroupStep implements Step
{
    use AuthorisesIngress;
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (Manifest::scoutDriver() !== 'meilisearch') {
            return StepResult::SKIPPED;
        }

        $securityGroup = new MeilisearchSecurityGroup();

        $dryRun = (bool) Arr::get($options, 'dry-run');
        $result = $this->syncResource($securityGroup, $options);

        $ruleMissing = $securityGroup->exists() && $this->reconcileIngressRule(
            $securityGroup->arn(),
            new LoadBalancerSecurityGroup(),
            'load balancer security group',
            MeilisearchService::PORT,
            'Enable load balancer traffic to the Meilisearch task',
            $dryRun,
        );

        if ($ruleMissing && $dryRun && $result === StepResult::SYNCED) {
            // The group already exists but the ingress rule is missing, so a
            // dry-run has a pending change to report rather than a clean SYNCED.
            return StepResult::WOULD_SYNC;
        }

        return $result;
    }
}
