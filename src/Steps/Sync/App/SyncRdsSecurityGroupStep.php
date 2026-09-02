<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Rds;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Concerns\AuthorisesTaskIngress;
use Codinglabs\Yolo\Resources\Ec2\RdsSecurityGroup;

/**
 * Runs in sync:app (after SyncTaskSecurityGroupStep) rather than
 * sync:environment because the ingress source is the ECS task SG, which
 * sync:app creates. With no port to derive — no `database:` declared yet, or a
 * describe this tier can't make — the group is still provisioned but NO ingress
 * rule is written: guessing a port would leave a speculative rule on a shared,
 * long-lived group that sync can never revoke. The rule is purely additive
 * (see AuthorisesTaskIngress).
 */
class SyncRdsSecurityGroupStep implements Step
{
    use AuthorisesTaskIngress;
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        $securityGroup = new RdsSecurityGroup();

        $dryRun = (bool) Arr::get($options, 'dry-run');
        $result = $this->syncResource($securityGroup, $options);

        $port = Rds::port();

        if ($port === null) {
            return $result;
        }

        $description = 'Enable Fargate tasks to connect to RDS';

        if ($securityGroup->exists() && $this->reconcileTaskIngressRule($securityGroup->arn(), $port, $description, $dryRun) && $dryRun && $result === StepResult::SYNCED) {
            // Group exists but the rule is missing — pending, not a clean SYNCED.
            return StepResult::WOULD_SYNC;
        }

        return $result;
    }
}
