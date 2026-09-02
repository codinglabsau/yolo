<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\App;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Aws\Rds;
use Codinglabs\Yolo\EnvManifest;
use Illuminate\Support\Collection;
use Aws\Rds\Exception\RdsException;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Ec2\Vpc;
use Codinglabs\Yolo\Concerns\RecordsWarnings;
use Codinglabs\Yolo\Concerns\AuthorisesTaskIngress;
use Codinglabs\Yolo\Contracts\SkippedByDeployCheck;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Authorises this app's tasks to reach an EXTERNALLY-hosted database (the
 * peered-migration posture). Everything beyond the manifest `database:` is
 * discovered live — VPC, port, attached security group — because discovery
 * can't go stale the way a declared group id would; a same-region peered SG can
 * reference the task SG directly. Two or more attached groups is an ambiguous
 * target, surfaced as a warning to wire by hand.
 *
 * {@see SkippedByDeployCheck}: the deploy gate's tier may not hold the RDS +
 * foreign-SG reads, and an externally-hosted database must never block a deploy.
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
            // The dashboard step hard-fails on this and the audit's posture probe reports it.
            return StepResult::SKIPPED;
        }

        if ($target === null) {
            return StepResult::SKIPPED;
        }

        try {
            $discovered = $this->discover($target);
        } catch (RdsException) {
            // The audit's posture probe owns reporting this.
            return StepResult::SKIPPED;
        }

        if ($discovered === null) {
            return StepResult::SKIPPED;
        }

        [$databaseVpcId, $securityGroupIds, $port] = $discovered;

        $rule = $port === null
            ? 'task-SG ingress rule'
            : sprintf('%d/tcp-from-task-SG rule', $port);

        if ($this->inEnvironmentVpc($databaseVpcId)) {
            return StepResult::SKIPPED;
        }

        // A cross-VPC SG reference is only valid over an ACTIVE peering, so an
        // unpeered, undeclared VPC gets a nudge rather than a mid-apply AWS error.
        // A declared-but-not-yet-active peer proceeds: the env tier activates it
        // earlier in the same sync.
        if ($databaseVpcId === null || ! $this->reachable($databaseVpcId)) {
            $this->recordWarning(sprintf(
                'The database "%s" is externally hosted (%s) with no peering to its VPC — the %s was not written. Declare the VPC in the env manifest `peering` list to bridge it.',
                $target['identifier'],
                $databaseVpcId ?? 'unknown VPC',
                $rule,
            ));

            return StepResult::SKIPPED;
        }

        if ($securityGroupIds->count() !== 1) {
            $this->recordWarning(sprintf(
                'The external database "%s" carries %d attached security groups — ambiguous, so the %s was not written. Add it to the right group by hand (`yolo audit` verifies it).',
                $target['identifier'],
                $securityGroupIds->count(),
                $rule,
            ));

            return StepResult::SKIPPED;
        }

        // No port yet (instance still creating): guessing one would leave a rule
        // on a foreign group YOLO can never revoke.
        if ($port === null) {
            return StepResult::SKIPPED;
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');

        if ($this->reconcileTaskIngressRule($securityGroupIds->first(), $port, 'Enable Fargate tasks to connect to the external database', $dryRun, foreign: true)) {
            return $dryRun ? StepResult::WOULD_SYNC : StepResult::SYNCED;
        }

        return StepResult::SYNCED;
    }

    /**
     * @param  array{identifier: string, cluster: bool}  $target
     * @return array{0: string|null, 1: Collection<int, string>, 2: int|null}|null
     */
    protected function discover(array $target): ?array
    {
        $record = $target['cluster'] ? Rds::cluster($target['identifier']) : Rds::instance($target['identifier']);

        if ($record === null) {
            return null;
        }

        // A cluster record's DBSubnetGroup is just the group's NAME — the VPC
        // is a per-member fact, so read it off a member instance.
        $vpcId = $target['cluster']
            ? (Rds::clusterInstances($target['identifier'])[0]['DBSubnetGroup']['VpcId'] ?? null)
            : ($record['DBSubnetGroup']['VpcId'] ?? null);

        return [
            $vpcId,
            collect($record['VpcSecurityGroups'] ?? [])->pluck('VpcSecurityGroupId')->filter()->values(),
            Rds::portFromRecord($record, $target['cluster']),
        ];
    }

    /**
     * In the env VPC it's the managed posture — SyncRdsSecurityGroupStep's rule
     * is the path. No env VPC yet (greenfield plan) can't hold an in-VPC
     * database, so absence reads as external.
     */
    protected function inEnvironmentVpc(?string $databaseVpcId): bool
    {
        try {
            return $databaseVpcId === (new Vpc())->arn();
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    /**
     * An active peering (YOLO-owned or not), or a declared one — the env tier
     * brings a declared peering active before this step's apply runs.
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
