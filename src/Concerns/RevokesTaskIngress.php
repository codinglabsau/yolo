<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Aws\Rds;
use Codinglabs\Yolo\Resources\Ec2\EcsTaskSecurityGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Teardown mirror of {@see AuthorisesTaskIngress}: revoke the "<port> from this
 * app's Fargate task SG" ingress rule from a shared security group (the
 * database's own port, Valkey cache 6379) so the task SG can then be deleted —
 * AWS refuses to delete a security group another group's rule still references.
 *
 * Only this app's own rule is revoked: it's matched by the referenced task-SG id
 * + protocol/port, so a sibling app's rule on the same shared group is never
 * touched. Records the change and writes nothing under --dry-run. Returns whether
 * a rule was present (a change is pending/applied).
 *
 * {@see self::revokeAllTaskIngressRules()} is the port-agnostic form, for a group
 * whose port is derived rather than fixed.
 */
trait RevokesTaskIngress
{
    use RecordsChanges;

    protected function revokeTaskIngressRule(string $groupId, int $port, bool $dryRun): bool
    {
        return $this->revokeTaskIngress($groupId, $port, $dryRun);
    }

    /**
     * Revoke EVERY tcp rule on the group that references this app's task SG,
     * whatever its port. For a database group the port is a derived fact
     * ({@see Rds::port()}), so a rule can outlive the port that wrote it — a sync
     * run before the database existed authorises the fallback port, and a database
     * moved to a new port leaves the old rule behind. Any such rule still
     * references the task SG, and AWS refuses to
     * delete a security group another group's rule points at, so a port-exact
     * revoke wedges the whole teardown. Every rule referencing THIS app's task
     * SG is this app's to reclaim, so sweeping them is both safe and complete.
     */
    protected function revokeAllTaskIngressRules(string $groupId, bool $dryRun): bool
    {
        return $this->revokeTaskIngress($groupId, null, $dryRun);
    }

    /**
     * @param  int|null  $port  the exact port to match, or null for every port
     */
    private function revokeTaskIngress(string $groupId, ?int $port, bool $dryRun): bool
    {
        try {
            $taskSecurityGroupId = (new EcsTaskSecurityGroup())->arn();
        } catch (ResourceDoesNotExistException) {
            // The task SG is already gone, so nothing references it — the rule
            // it would have authorised can't still exist.
            return false;
        }

        $rules = collect(Ec2::securityGroupRules($groupId))->filter(
            fn (array $rule): bool => ! ($rule['IsEgress'] ?? false)
                && ($rule['IpProtocol'] ?? null) === 'tcp'
                && ($port === null || ($rule['FromPort'] ?? null) === $port)
                && ($rule['ReferencedGroupInfo']['GroupId'] ?? null) === $taskSecurityGroupId
        );

        if ($rules->isEmpty()) {
            return false;
        }

        foreach ($rules as $rule) {
            $this->recordChange(Change::make(
                sprintf('ingress %s/tcp from task security group', $rule['FromPort'] ?? '?'),
                $taskSecurityGroupId,
                null,
            ));
        }

        if (! $dryRun) {
            Aws::ec2()->revokeSecurityGroupIngress([
                'GroupId' => $groupId,
                'SecurityGroupRuleIds' => $rules->pluck('SecurityGroupRuleId')->all(),
            ]);
        }

        return true;
    }
}
