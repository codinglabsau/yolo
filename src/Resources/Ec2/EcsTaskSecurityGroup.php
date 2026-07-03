<?php

namespace Codinglabs\Yolo\Resources\Ec2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Enums\SecurityGroup;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Models the security group identity + tags only. Ingress-rule management
 * (the load-balancer-to-task port-allow rule) lives in SyncTaskSecurityGroupStep
 * because it's a separate AWS concept with its own reconciliation surface.
 */
class EcsTaskSecurityGroup implements Deletable, Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName(SecurityGroup::ECS_TASK_SECURITY_GROUP);
    }

    public function scope(): Scope
    {
        return Scope::App;
    }

    public function exists(): bool
    {
        try {
            Ec2::securityGroup($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return Ec2::securityGroup($this->name())['GroupId'];
    }

    public function create(): void
    {
        Aws::ec2()->createSecurityGroup([
            'Description' => 'Enable load balancer traffic to Fargate task ENI',
            'GroupName' => $this->name(),
            'VpcId' => (new Vpc())->arn(),
            'TagSpecifications' => [
                [
                    'ResourceType' => 'security-group',
                    ...Aws::tags($this->tags()),
                ],
            ],
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseEc2Tags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Delete the task security group. Upstream teardown revokes the sibling-SG
     * ingress rules that reference it (RDS 3306, cache 6379, Typesense 8108) and
     * stops the ECS tasks first — but a stopped Fargate task's ENI keeps holding
     * the group for a minute or two while it detaches, so the delete is retried
     * past that transient DependencyViolation until the ENIs clear (and a
     * concurrent removal is tolerated). See Ec2::deleteSecurityGroupWhenDetached.
     */
    public function delete(): void
    {
        Ec2::deleteSecurityGroupWhenDetached($this->arn());
    }
}
