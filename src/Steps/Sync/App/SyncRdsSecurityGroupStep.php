<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\AuthorisesIngress;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Ec2\RdsSecurityGroup;

/**
 * Provisions the RDS security group and authorises the Fargate tasks to reach
 * the database on 3306. Runs in sync:app (after SyncTaskSecurityGroupStep)
 * rather than sync:environment, because the ingress source is the ECS task SG,
 * which sync:app creates — the RDS subnet group stays in sync:environment.
 *
 * The ingress rule is managed purely additively (see AuthorisesIngress).
 */
class SyncRdsSecurityGroupStep implements Step
{
    use AuthorisesIngress;
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $securityGroup = new RdsSecurityGroup();

        if (Manifest::has('rds.security-group') && $securityGroup->exists()) {
            return StepResult::CUSTOM_MANAGED;
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');
        $result = $this->syncResource($securityGroup, $options);

        $description = 'Enable Fargate tasks to connect to RDS';

        if ($securityGroup->exists() && $this->reconcileTaskIngressRule($securityGroup->arn(), 3306, $description, $dryRun) && $dryRun && $result === StepResult::SYNCED) {
            // The group already exists but the ingress rule is missing, so a
            // dry-run has a pending change to report rather than a clean SYNCED.
            return StepResult::WOULD_SYNC;
        }

        return $result;
    }
}
