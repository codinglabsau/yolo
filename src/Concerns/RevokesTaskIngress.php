<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Resources\Ec2\EcsTaskSecurityGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Teardown mirror of {@see AuthorisesTaskIngress}: revoke the "<port> from this
 * app's Fargate task SG" ingress rule from a shared security group (RDS 3306,
 * Valkey cache 6379) so the task SG can then be deleted — AWS refuses to delete
 * a security group another group's rule still references.
 *
 * Only this app's own rule is revoked: it's matched by the referenced task-SG id
 * + protocol/port, so a sibling app's rule on the same shared group is never
 * touched. Records the change and writes nothing under --dry-run. Returns whether
 * a rule was present (a change is pending/applied).
 */
trait RevokesTaskIngress
{
    use RecordsChanges;

    protected function revokeTaskIngressRule(string $groupId, int $port, bool $dryRun): bool
    {
        try {
            $taskSecurityGroupId = (new EcsTaskSecurityGroup())->arn();
        } catch (ResourceDoesNotExistException) {
            // The task SG is already gone, so nothing references it — the rule
            // it would have authorised can't still exist.
            return false;
        }

        $rule = collect(Ec2::securityGroupRules($groupId))->first(
            fn (array $rule): bool => ! ($rule['IsEgress'] ?? false)
                && ($rule['IpProtocol'] ?? null) === 'tcp'
                && ($rule['FromPort'] ?? null) === $port
                && ($rule['ReferencedGroupInfo']['GroupId'] ?? null) === $taskSecurityGroupId
        );

        if ($rule === null) {
            return false;
        }

        $this->recordChange(Change::make("ingress {$port}/tcp from task security group", $taskSecurityGroupId, null));

        if (! $dryRun) {
            Aws::ec2()->revokeSecurityGroupIngress([
                'GroupId' => $groupId,
                'SecurityGroupRuleIds' => [$rule['SecurityGroupRuleId']],
            ]);
        }

        return true;
    }
}
