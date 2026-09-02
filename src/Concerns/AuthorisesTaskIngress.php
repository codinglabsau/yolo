<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Aws\Rds;
use Codinglabs\Yolo\Resources\Ec2\EcsTaskSecurityGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Additive only — an out-of-band rule is never revoked. The caller derives the
 * port (a database's off the live record, {@see Rds::port()}, never an assumed
 * engine). A group YOLO doesn't own is a foreign write — pass $foreign so the
 * plan names the group as not yolo-managed.
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
            // The plan pass on a fresh environment reaches here before the task SG exists.
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
