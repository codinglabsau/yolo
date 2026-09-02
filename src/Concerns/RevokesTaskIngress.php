<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Aws\Rds;
use Codinglabs\Yolo\Resources\Ec2\EcsTaskSecurityGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Teardown mirror of {@see AuthorisesTaskIngress}. AWS refuses to delete a
 * security group another group's rule still references, so the task SG's rules
 * on shared groups must go first. Rules are matched by the referenced task-SG id,
 * so a sibling app's rule on the same shared group is never touched.
 */
trait RevokesTaskIngress
{
    use RecordsChanges;

    protected function revokeTaskIngressRule(string $groupId, int $port, bool $dryRun): bool
    {
        return $this->revokeTaskIngress($groupId, $port, $dryRun);
    }

    /**
     * For a database group the port is derived ({@see Rds::port()}), so a rule can
     * outlive the port that wrote it and a port-exact revoke would wedge the
     * teardown. Every rule referencing THIS app's task SG is this app's to reclaim.
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
            // The task SG is already gone, so nothing can reference it.
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
