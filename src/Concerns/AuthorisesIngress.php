<?php

namespace Codinglabs\Yolo\Concerns;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Ec2\EcsTaskSecurityGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Additively ensures a "<port> from <source SG>" ingress rule exists on a shared
 * security group (RDS 3306 / Valkey 6379 from the task SG, Meilisearch 7700 from
 * the load balancer SG, …). The rule is identified by its content — AWS rejects
 * duplicate permissions, so no marker tag is needed. Never revokes (any
 * out-of-band rule is left untouched), records the change it makes, and writes
 * nothing under --dry-run. Returns whether the rule was missing (a change is
 * pending/applied).
 */
trait AuthorisesIngress
{
    use RecordsChanges;

    protected function reconcileTaskIngressRule(string $groupId, int $port, string $description, bool $dryRun): bool
    {
        return $this->reconcileIngressRule($groupId, new EcsTaskSecurityGroup(), 'task security group', $port, $description, $dryRun);
    }

    protected function reconcileIngressRule(string $groupId, Resource $source, string $sourceLabel, int $port, string $description, bool $dryRun): bool
    {
        try {
            // Name lookup throws if the source SG is missing — sync provisions it
            // before this step runs, but a dry-run on a fresh environment can reach
            // here before it exists.
            $sourceGroupId = $source->arn();
        } catch (ResourceDoesNotExistException) {
            $this->recordChange(Change::make("ingress {$port}/tcp from {$sourceLabel}", null, "authorised ({$sourceLabel} pending)"));

            return true;
        }

        $alreadyAuthorised = collect(Ec2::securityGroupRules($groupId))->contains(
            fn (array $rule): bool => ! ($rule['IsEgress'] ?? false)
                && ($rule['IpProtocol'] ?? null) === 'tcp'
                && ($rule['FromPort'] ?? null) === $port
                && ($rule['ReferencedGroupInfo']['GroupId'] ?? null) === $sourceGroupId
        );

        if ($alreadyAuthorised) {
            return false;
        }

        $this->recordChange(Change::make("ingress {$port}/tcp from {$sourceLabel}", null, $sourceGroupId));

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
                                'GroupId' => $sourceGroupId,
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
