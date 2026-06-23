<?php

namespace Codinglabs\Yolo\Resources\Ec2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Enums\SecurityGroup;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Shared security group attached to RDS. Models identity + tags only; the
 * 3306-from-task-SG ingress rule is reconciled additively by
 * SyncRdsSecurityGroupStep. Point `rds.security-group` at an existing group
 * to adopt one (reported CUSTOM_MANAGED, never mutated).
 */
class RdsSecurityGroup implements Deletable, Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return Manifest::get('rds.security-group', $this->keyedName(SecurityGroup::RDS_SECURITY_GROUP));
    }

    public function scope(): Scope
    {
        return Scope::Env;
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
            'Description' => 'Enable Fargate tasks to connect to RDS',
            'GroupName' => $this->name(),
            'VpcId' => (new Vpc())->arn(),
            'TagSpecifications' => [
                ['ResourceType' => 'security-group', ...Aws::tags($this->tags())],
            ],
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseEc2Tags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Delete the RDS security group (env teardown, only once no database remains
     * in the group and the 3306-from-task-SG ingress rule went with the task
     * security group). A detaching ENI can still hold the group briefly, so the
     * delete is retried past that transient DependencyViolation until it clears
     * (and a concurrent removal is tolerated). See Ec2::deleteSecurityGroupWhenDetached.
     */
    public function delete(): void
    {
        Ec2::deleteSecurityGroupWhenDetached($this->arn());
    }
}
