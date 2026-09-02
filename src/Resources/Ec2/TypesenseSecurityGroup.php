<?php

namespace Codinglabs\Yolo\Resources\Ec2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Enums\SecurityGroup;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;

/**
 * Identity + tags only; the ingress rules are reconciled additively by
 * SyncTypesenseSecurityGroupStep, and consuming apps add their own task-SG
 * ingress app-side (the RDS pattern).
 */
class TypesenseSecurityGroup implements Deletable, Resource
{
    use ResolvesSecurityGroup;
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName(SecurityGroup::TYPESENSE_SECURITY_GROUP);
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function create(): void
    {
        Aws::ec2()->createSecurityGroup([
            'Description' => 'Typesense node tasks - search API from the load balancer, Raft peering node-to-node',
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

    public function delete(): void
    {
        Ec2::deleteSecurityGroupWhenDetached($this->arn());
    }
}
