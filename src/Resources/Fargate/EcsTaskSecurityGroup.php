<?php

namespace Codinglabs\Yolo\Resources\Fargate;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Enums\SecurityGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Models the security group identity + tags only. Ingress-rule management
 * (the load-balancer-to-task port-allow rule) lives in SyncTaskSecurityGroupStep
 * because it's a separate AWS concept with its own reconciliation surface.
 */
class EcsTaskSecurityGroup implements Resource
{
    public function name(): string
    {
        return Helpers::keyedResourceName(SecurityGroup::ECS_TASK_SECURITY_GROUP, exclusive: true);
    }

    public function tags(): array
    {
        return ['Name' => $this->name()];
    }

    public function exists(): bool
    {
        try {
            AwsResources::ecsTaskSecurityGroup();

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return AwsResources::ecsTaskSecurityGroup()['GroupId'];
    }

    public function create(): void
    {
        Aws::ec2()->createSecurityGroup([
            'Description' => 'Enable load balancer traffic to Fargate task ENI',
            'GroupName' => $this->name(),
            'VpcId' => AwsResources::vpc()['VpcId'],
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
