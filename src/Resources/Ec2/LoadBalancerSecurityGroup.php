<?php

namespace Codinglabs\Yolo\Resources\Ec2;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Enums\Scope;
use Aws\Ec2\Exception\Ec2Exception;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Enums\SecurityGroup;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Shared security group fronting the load balancer. Models identity + tags only;
 * the HTTP/HTTPS ingress rules are reconciled by SyncLoadBalancerSecurityGroupStep
 * (rules are a separate AWS concept with their own diff surface).
 */
class LoadBalancerSecurityGroup implements Deletable, Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName(SecurityGroup::LOAD_BALANCER_SECURITY_GROUP);
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

    /**
     * Delete the security group. Assumes upstream teardown has already removed
     * everything that references it — the load balancer is gone (so no live ENIs
     * hold the group) and the task security group's ingress rule referencing it
     * has been revoked — so a plain delete succeeds; AWS would otherwise refuse
     * with DependencyViolation. A concurrent removal (InvalidGroup.NotFound) is
     * tolerated.
     */
    public function delete(): void
    {
        try {
            Aws::ec2()->deleteSecurityGroup(['GroupId' => $this->arn()]);
        } catch (Ec2Exception $e) {
            if ($e->getAwsErrorCode() === 'InvalidGroup.NotFound') {
                return;
            }

            throw $e;
        }
    }
}
