<?php

namespace Codinglabs\Yolo\Resources\Ec2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Enums\SecurityGroup;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;

/** Identity + tags only; the HTTP/HTTPS ingress rules live in SyncLoadBalancerSecurityGroupStep. */
class LoadBalancerSecurityGroup implements Deletable, Resource
{
    use ResolvesSecurityGroup;
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName(SecurityGroup::LOAD_BALANCER_SECURITY_GROUP);
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function create(): void
    {
        Aws::ec2()->createSecurityGroup([
            'Description' => 'Enable HTTP and HTTPS from anywhere',
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

    /** The deleted ALB's ENIs detach asynchronously and hold the group for a short window. */
    public function delete(): void
    {
        Ec2::deleteSecurityGroupWhenDetached($this->arn());
    }
}
