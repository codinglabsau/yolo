<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Concerns\RevokesTaskIngress;
use Codinglabs\Yolo\Resources\Ec2\RdsSecurityGroup;

/**
 * Revokes this app's database ingress rules from the shared RDS security group —
 * never the group itself, which stays for the database and the environment's
 * other apps. Must run before the task SG is deleted (AWS refuses to delete a
 * security group another group's rule still references), and every port is swept
 * rather than just the database's current one: the port is derived, so a rule
 * written under a fallback or a since-changed port would otherwise wedge the
 * teardown.
 */
class RevokeRdsIngressStep implements ExecutesWebStep
{
    use RevokesTaskIngress;

    public function __invoke(array $options): StepResult
    {
        $securityGroup = new RdsSecurityGroup();

        if (! $securityGroup->exists()) {
            return StepResult::SKIPPED;
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');

        if (! $this->revokeAllTaskIngressRules($securityGroup->arn(), $dryRun)) {
            return StepResult::SKIPPED;
        }

        return $dryRun ? StepResult::WOULD_DELETE : StepResult::DELETED;
    }
}
