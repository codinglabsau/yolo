<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Concerns\RevokesTaskIngress;
use Codinglabs\Yolo\Resources\Ec2\RdsSecurityGroup;

/**
 * Only this app's rules go — the group stays for the other apps. Must run
 * before the task SG is deleted: AWS refuses to delete a security group another
 * group's rule still references. Every port is swept, not just the current one:
 * the port is derived, so a rule written under a since-changed port would
 * otherwise wedge the teardown.
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
