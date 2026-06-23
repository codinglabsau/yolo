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
 * Shared security group attached to the Valkey cache. Models identity + tags
 * only; the 6379-from-task-SG ingress rule is reconciled additively by
 * SyncCacheSecurityGroupStep. Mirrors RdsSecurityGroup.
 */
class CacheSecurityGroup implements Deletable, Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName(SecurityGroup::CACHE_SECURITY_GROUP);
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
            'Description' => 'Enable Fargate tasks to connect to the Valkey cache',
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
     * Teardown: delete the security group, after the cache that used it is gone
     * and apps have revoked their 6379 ingress. A concurrent not-found is tolerated.
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
