<?php

namespace Codinglabs\Yolo\Resources\ElastiCache;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\Ec2;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Aws\ElastiCache;
use Codinglabs\Yolo\Resources\Ec2\Vpc;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Aws\ElastiCache\Exception\ElastiCacheException;
use Codinglabs\Yolo\Enums\ElastiCache as ElastiCacheEnum;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * ElastiCache subnet group spanning every subnet in the VPC, so the Valkey
 * cache can launch into the YOLO network. Mirrors RdsSubnet.
 */
class CacheSubnetGroup implements Deletable, Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName(ElastiCacheEnum::CACHE_SUBNET_GROUP);
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        try {
            ElastiCache::cacheSubnetGroup($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return ElastiCache::cacheSubnetGroup($this->name())['ARN'];
    }

    public function create(): void
    {
        Aws::elastiCache()->createCacheSubnetGroup([
            'CacheSubnetGroupName' => $this->name(),
            'CacheSubnetGroupDescription' => 'YOLO cache subnet group',
            'SubnetIds' => collect(Ec2::vpcSubnets((new Vpc())->arn()))->pluck('SubnetId')->all(),
            ...Aws::tags($this->tags()),
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseElastiCacheTags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Teardown: delete the subnet group, after the cache that used it is gone. A
     * concurrent not-found is tolerated.
     */
    public function delete(): void
    {
        try {
            Aws::elastiCache()->deleteCacheSubnetGroup([
                'CacheSubnetGroupName' => $this->name(),
            ]);
        } catch (ElastiCacheException $e) {
            if ($e->getAwsErrorCode() === 'CacheSubnetGroupNotFoundFault') {
                return;
            }

            throw $e;
        }
    }
}
