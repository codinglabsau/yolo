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
 * (a change is pending/applied).
 */
trait AuthorisesTaskIngress
{
    use RecordsChanges;

    protected function reconcileTaskIngressRule(string $groupId, int $port, string $description, bool $dryRun): bool
    {
        try {
            // Name lookup throws if the task SG is missing — sync:app provisions it
            // before this step runs, but a dry-run on a fresh environment can reach
            // here before it exists.
            $taskSecurityGroupId = (new EcsTaskSecurityGroup())->arn();
        } catch (ResourceDoesNotExistException) {
            $this->recordChange(Change::make("ingress {$port}/tcp from task security group", null, 'authorised (task SG pending)'));

            return true;
        }

        $alreadyAuthorised = collect(Ec2::securityGroupRules($groupId))->contains(
            fn (array $rule) => ! ($rule['IsEgress'] ?? false)
                && ($rule['IpProtocol'] ?? null) === 'tcp'
                && ($rule['FromPort'] ?? null) === $port
                && ($rule['ReferencedGroupInfo']['GroupId'] ?? null) === $taskSecurityGroupId
        );

        if ($alreadyAuthorised) {
            return false;
        }

        $this->recordChange(Change::make("ingress {$port}/tcp from task security group", null, $taskSecurityGroupId));

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
