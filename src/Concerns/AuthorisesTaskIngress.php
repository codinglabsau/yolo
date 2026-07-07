<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Resources\Ec2\EcsTaskSecurityGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Additively ensures a "<port> from the Fargate task SG" ingress rule exists on a
 * shared security group (RDS 3306, Valkey cache 6379, …). The rule is identified
 * by its content — AWS rejects duplicate permissions, so no marker tag is needed.
 * Never revokes (any out-of-band rule is left untouched), records the change it
 * makes, and writes nothing under --dry-run. Returns whether the rule was missing
 * (a change is pending/applied). A group YOLO doesn't own (an external
 * database's) is a foreign write — pass $foreign so the plan names the group
 * and marks it not yolo-managed.
 */
trait AuthorisesTaskIngress
{
    use RecordsChanges;

    protected function reconcileTaskIngressRule(string $groupId, int $port, string $description, bool $dryRun, bool $foreign = false): bool
    {
        $attribute = $foreign
            ? "ingress {$port}/tcp from task security group ({$groupId} — not yolo-managed)"
            : "ingress {$port}/tcp from task security group";

        try {
            // Name lookup throws if the task SG is missing — sync:app provisions it
            // before this step runs, but a dry-run on a fresh environment can reach
            // here before it exists.
            $taskSecurityGroupId = (new EcsTaskSecurityGroup())->arn();
        } catch (ResourceDoesNotExistException) {
            $this->recordChange(Change::make($attribute, null, 'authorised (task SG pending)'));

            return true;
        }

        $alreadyAuthorised = collect(Ec2::securityGroupRules($groupId))->contains(
            fn (array $rule): bool => ! ($rule['IsEgress'] ?? false)
                && ($rule['IpProtocol'] ?? null) === 'tcp'
                && ($rule['FromPort'] ?? null) === $port
                && ($rule['ReferencedGroupInfo']['GroupId'] ?? null) === $taskSecurityGroupId
        );

        if ($alreadyAuthorised) {
            return false;
        }

        $this->recordChange(Change::make($attribute, null, $taskSecurityGroupId));

        if (! $dryRun) {
            Aws::ec2()->authorizeSecurityGroupIngress([
                'GroupId' => $groupId,
                'IpPermissions' => [
                    [
                        'IpProtocol' => 'tcp',
                        'FromPort' => $port,
                        'ToPort' => $port,
                        'UserIdGroupPairs' => [
                            [
                                'GroupId' => $taskSecurityGroupId,
                                'Description' => $description,
                            ],
                        ],
                    ],
                ],
            ]);
        }

        return true;
    }
}
