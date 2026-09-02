<?php

namespace Codinglabs\Yolo\Resources\Ec2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Enums\SecurityGroup;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;

/** Identity + tags only; the ALB→task ingress rule lives in SyncTaskSecurityGroupStep. */
class EcsTaskSecurityGroup implements Deletable, Resource
{
    use ResolvesSecurityGroup;
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName(SecurityGroup::ECS_TASK_SECURITY_GROUP);
    }

    public function scope(): Scope
    {
        return Scope::App;
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

    /** A stopped Fargate task's ENI holds the group for a minute or two while it detaches. */
    public function delete(): void
    {
        Ec2::deleteSecurityGroupWhenDetached($this->arn());
    }
}
