<?php

namespace Codinglabs\Yolo\Resources\Ec2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ec2;
use Aws\Exception\AwsException;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Enums\SecurityGroup;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Security group on the Typesense node tasks. Models identity + tags; the
 * ingress rules (8108 from the ALB SG, plus 8108 and 8107 node-to-node) are
 * reconciled additively by SyncTypesenseSecurityGroupStep — consuming apps'
 * task-SG ingress arrives with the app-side consumption work, the RDS-3306
 * pattern.
 */
class TypesenseSecurityGroup implements Deletable, Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName(SecurityGroup::TYPESENSE_SECURITY_GROUP);
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
     * Teardown runs after the cluster cascade stopped the node tasks, but
     * their ENIs detach asynchronously — DependencyViolation for a short
     * window is expected, not an error. Bounded retry, then a genuine failure
     * propagates.
     */
    public function delete(): void
    {
        $groupId = $this->arn();
        $deadline = time() + 120;

        do {
            try {
                Aws::ec2()->deleteSecurityGroup(['GroupId' => $groupId]);

                return;
            } catch (AwsException $e) {
                if ($e->getAwsErrorCode() !== 'DependencyViolation' || time() >= $deadline) {
                    throw $e;
                }

                sleep(5);
            }
        } while (true);
    }
}
