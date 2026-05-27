<?php

namespace Codinglabs\Yolo\Resources\Fargate;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Enums\SecurityGroup;
use Codinglabs\Yolo\Resources\Network\Vpc;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Models the security group identity + tags only. Ingress-rule management
 * (the load-balancer-to-task port-allow rule) lives in SyncTaskSecurityGroupStep
 * because it's a separate AWS concept with its own reconciliation surface.
 */
class EcsTaskSecurityGroup implements Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return Manifest::get(
            'aws.ecs.security-group',
            $this->keyedName(SecurityGroup::ECS_TASK_SECURITY_GROUP),
        );
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

    public function synchroniseTags(): void
    {
        Aws::synchroniseEc2Tags($this->arn(), $this->tags());
    }
}
