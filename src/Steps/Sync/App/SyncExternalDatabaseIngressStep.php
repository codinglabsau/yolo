<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Aws\Rds;
use Codinglabs\Yolo\EnvManifest;
use Aws\Rds\Exception\RdsException;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Ec2\Vpc;
use Codinglabs\Yolo\Concerns\RecordsWarnings;
use Codinglabs\Yolo\Concerns\AuthorisesTaskIngress;
use Codinglabs\Yolo\Contracts\SkippedByDeployCheck;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Authorises this app's tasks to reach an EXTERNALLY-hosted database — the
 * peered-migration posture. The manifest `database:` is the only declaration;
 * everything else is discovered live: the instance's VPC (in the env VPC the
 * managed path's SyncRdsSecurityGroupStep owns the rule instead), and its
 * attached security group, which gets the same additive 3306-from-task-SG rule
 * (a same-region peered SG can reference the task SG directly). Discovery
 * can't go stale the way a declared group id would.
 *
 * Exactly one attached security group is required to write — two or more is an
 * ambiguous target, surfaced as a warning to wire by hand (the audit's
 * task-ingress probe independently verifies whichever rule exists).
 *
 * {@see SkippedByDeployCheck}: the deploy gate's tier may not hold the RDS +
 * foreign-SG reads this step needs, so `yolo sync` is its drift check — and an
 * externally-hosted database must never block a deploy.
 */
class SyncExternalDatabaseIngressStep implements SkippedByDeployCheck, Step
{
    use AuthorisesTaskIngress;
    use RecordsWarnings;

    public function __invoke(array $options): StepResult
    {
        try {
            $target = Rds::target();
        } catch (RdsException|ResourceDoesNotExistException) {
            // Unreadable or matching nothing (the dashboard step hard-fails on
            // that, and the audit's posture probe reports it) — sync moves on.
            return StepResult::SKIPPED;
        }

        if ($target === null || $target['cluster']) {
            // Nothing declared, or an Aurora cluster (wire ingress by hand for
            // now) — nothing to reconcile.
            return StepResult::SKIPPED;
        }

        try {
            $instance = Rds::instance($target['identifier']);
        } catch (RdsException) {
            // Unreadable (not found, or denied under this tier) — the audit's
            // posture probe owns reporting that; sync just moves on.
            return StepResult::SKIPPED;
        }

        if ($instance === null || $this->inEnvironmentVpc($instance)) {
            return StepResult::SKIPPED;
        }

        $databaseVpcId = $instance['DBSubnetGroup']['VpcId'] ?? null;

        // A cross-VPC security-group reference is only valid over an ACTIVE
        // peering, so an external database whose VPC is neither peered nor
        // declared for peering gets a nudge, not a mid-apply AWS error. A
        // declared-but-not-yet-active peer proceeds: the env tier activates it
        // earlier in the same sync, so by the time this step's apply runs the
        // reference is valid (and the plan pass reports the pending rule).
        if ($databaseVpcId === null || ! $this->reachable($databaseVpcId)) {
            $this->recordWarning(sprintf(
                'The database "%s" is externally hosted (%s) with no peering to its VPC — the 3306-from-task-SG rule was not written. Declare the VPC in the env manifest `peering` list to bridge it.',
                $target['identifier'],
                $databaseVpcId ?? 'unknown VPC',
            ));

            return StepResult::SKIPPED;
        }

        $securityGroupIds = collect($instance['VpcSecurityGroups'] ?? [])
            ->pluck('VpcSecurityGroupId')
            ->filter()
            ->values();

        if ($securityGroupIds->count() !== 1) {
            $this->recordWarning(sprintf(
                'The external database "%s" carries %d attached security groups — ambiguous, so the 3306-from-task-SG rule was not written. Add it to the right group by hand (`yolo audit` verifies it).',
                $target['identifier'],
                $securityGroupIds->count(),
            ));

            return StepResult::SKIPPED;
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');

        if ($this->reconcileTaskIngressRule($securityGroupIds->first(), 3306, 'Enable Fargate tasks to connect to the external database', $dryRun, foreign: true)) {
            return $dryRun ? StepResult::WOULD_SYNC : StepResult::SYNCED;
        }

        return StepResult::SYNCED;
    }

    /**
     * Whether the instance already sits in the environment's VPC — then it's
     * the managed posture and the shared RDS security group's rule (written by
     * SyncRdsSecurityGroupStep) is the path, not this step. A greenfield plan
     * pass (no env VPC yet) can't have an in-VPC database, so absence reads as
     * external-or-unknown and the VPC comparison simply won't match.
     */
    protected function inEnvironmentVpc(array $instance): bool
    {
        try {
            return ($instance['DBSubnetGroup']['VpcId'] ?? null) === (new Vpc())->arn();
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    /**
     * Whether the database's VPC is reachable for a cross-VPC SG reference —
     * an active peering already joins it to the env VPC (YOLO-owned or not),
     * or the env manifest declares it, meaning this same sync's environment
     * tier brings the peering active before this step's apply runs.
     */
    protected function reachable(string $databaseVpcId): bool
    {
        try {
            if (Ec2::activePeeringBetween((new Vpc())->arn(), $databaseVpcId)) {
                return true;
            }
        } catch (ResourceDoesNotExistException) {
            // No env VPC yet (greenfield) — fall through to the declaration.
        }

        return in_array($databaseVpcId, EnvManifest::peering(), true);
    }
}
