<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Rds;
use Codinglabs\Yolo\Manifest;
use Aws\Rds\Exception\RdsException;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Concerns\RevokesTaskIngress;

/**
 * Revokes this app's "3306 from the task SG" rule from an externally-hosted
 * database's security group (the peered posture) — the mirror of
 * SyncExternalDatabaseIngressStep. Without it, the foreign rule still
 * references the task SG and AWS refuses to delete the group. Every attached
 * SG is swept (the rule is matched by content, so only YOLO's own rule is
 * ever revoked); an unreadable database just skips — nothing referenced.
 */
class RevokeExternalDatabaseIngressStep implements ExecutesWebStep
{
    use RevokesTaskIngress;

    public function __invoke(array $options): StepResult
    {
        $target = Manifest::rdsTarget();

        if ($target === null || $target['cluster']) {
            return StepResult::SKIPPED;
        }

        try {
            $instance = Rds::instance($target['identifier']);
        } catch (RdsException) {
            return StepResult::SKIPPED;
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');
        $revoked = false;

        foreach (collect($instance['VpcSecurityGroups'] ?? [])->pluck('VpcSecurityGroupId')->filter() as $securityGroupId) {
            $revoked = $this->revokeTaskIngressRule($securityGroupId, 3306, $dryRun) || $revoked;
        }

        if (! $revoked) {
            return StepResult::SKIPPED;
        }

        return $dryRun ? StepResult::WOULD_DELETE : StepResult::DELETED;
    }
}
