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
 * Security group on the Typesense node tasks. Models identity + tags; the
 * ingress rules (8108 from the ALB SG, plus 8108 and 8107 node-to-node) are
 * reconciled additively by SyncTypesenseSecurityGroupStep — consuming apps'
 * task-SG ingress arrives with the app-side consumption work, the RDS-3306
 * pattern.
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

    /**
     * Teardown runs after the cluster cascade stopped the node tasks, but their
     * ENIs detach asynchronously — DependencyViolation for a short window is
     * expected, not an error. The delete is retried past it until the ENIs clear.
     * See Ec2::deleteSecurityGroupWhenDetached.
     */
    public function delete(): void
    {
        Ec2::deleteSecurityGroupWhenDetached($this->arn());
    }
}
