<?php

namespace Codinglabs\Yolo\Resources\Network;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Enums\SecurityGroup;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Shared security group attached to RDS. Models identity + tags only; the
 * 3306-from-task-SG ingress rule is reconciled additively by
 * SyncRdsSecurityGroupStep. Point `aws.rds.security-group` at an existing group
 * to adopt one (reported CUSTOM_MANAGED, never mutated).
 */
class RdsSecurityGroup implements Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return Manifest::get('aws.rds.security-group', $this->keyedName(SecurityGroup::RDS_SECURITY_GROUP));
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

    public function synchroniseTags(): void
    {
        Aws::synchroniseEc2Tags($this->arn(), $this->tags());
    }
}
