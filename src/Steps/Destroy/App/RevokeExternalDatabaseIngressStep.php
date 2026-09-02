<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Rds;
use Aws\Rds\Exception\RdsException;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\ExecutesWebStep;
use Codinglabs\Yolo\Concerns\RevokesTaskIngress;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Must run before the task SG is deleted (see RevokeRdsIngressStep). Every
 * attached SG is swept, and on each every port referencing the task SG — the
 * port is derived, so a stale one must not wedge the teardown; rules are matched
 * by content, so a sibling app's is never touched.
 */
class RevokeExternalDatabaseIngressStep implements ExecutesWebStep
{
    use RevokesTaskIngress;

    public function __invoke(array $options): StepResult
    {
        try {
            $target = Rds::target();
        } catch (RdsException|ResourceDoesNotExistException) {
            // A database that no longer resolves referenced nothing — don't wedge on it.
            return StepResult::SKIPPED;
        }

        if ($target === null) {
            return StepResult::SKIPPED;
        }

        try {
            $record = $target['cluster'] ? Rds::cluster($target['identifier']) : Rds::instance($target['identifier']);
        } catch (RdsException) {
            return StepResult::SKIPPED;
        }

        if ($record === null) {
            return StepResult::SKIPPED;
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');
        $revoked = false;

        foreach (collect($record['VpcSecurityGroups'] ?? [])->pluck('VpcSecurityGroupId')->filter() as $securityGroupId) {
            $revoked = $this->revokeAllTaskIngressRules($securityGroupId, $dryRun) || $revoked;
        }

        if (! $revoked) {
            return StepResult::SKIPPED;
        }

        return $dryRun ? StepResult::WOULD_DELETE : StepResult::DELETED;
    }
}
