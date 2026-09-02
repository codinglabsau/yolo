<?php

namespace Codinglabs\Yolo\Resources\ElastiCache;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Aws\ElastiCache;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Aws\ElastiCache\Exception\ElastiCacheException;
use Codinglabs\Yolo\Resources\Ec2\CacheSecurityGroup;
use Codinglabs\Yolo\Enums\ElastiCache as ElastiCacheEnum;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * Single-node replication group (0 replicas): the cheapest topology that still uses
 * the replica-ready construct, so HA later is an in-place add.
 */
class CacheCluster implements Deletable, Resource
{
    use ResolvesTags;

    public const ENGINE = 'valkey';

    // Pinned as a matched pair — the parameter group family is coupled to the
    // engine major.
    public const ENGINE_VERSION = '9.0';

    public const PARAMETER_GROUP_FAMILY = 'valkey9';

    public const NODE_TYPE = 'cache.t4g.micro';

    public const PORT = 6379;

    public function name(): string
    {
        return $this->keyedName(ElastiCacheEnum::CACHE_CLUSTER);
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        try {
            ElastiCache::replicationGroup($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return ElastiCache::replicationGroup($this->name())['ARN'];
    }

    /** Cluster mode is disabled, so there is exactly one node group. */
    public function endpoint(): string
    {
        return ElastiCache::replicationGroup($this->name())['NodeGroups'][0]['PrimaryEndpoint']['Address'];
    }

    public function create(): void
    {
        Aws::elastiCache()->createReplicationGroup([
            'ReplicationGroupId' => $this->name(),
            'ReplicationGroupDescription' => 'YOLO shared Valkey cache',
            'Engine' => self::ENGINE,
            'EngineVersion' => self::ENGINE_VERSION,
            'CacheNodeType' => self::NODE_TYPE,
            'NumCacheClusters' => 1,
            'AutomaticFailoverEnabled' => false,
            'MultiAZEnabled' => false,
            'AtRestEncryptionEnabled' => true,
            // Valkey has no default once any encryption setting is set. TLS in-transit
            // is deferred — the cache is SG-locked to the task SG, plaintext stays in-VPC.
            'TransitEncryptionEnabled' => false,
            'Port' => self::PORT,
            'CacheSubnetGroupName' => (new CacheSubnetGroup())->name(),
            'CacheParameterGroupName' => (new CacheParameterGroup())->name(),
            'SecurityGroupIds' => [(new CacheSecurityGroup())->arn()],
            ...Aws::tags($this->tags()),
        ]);

        // A fresh Valkey cluster routinely outlasts the SDK's 10-minute default.
        Aws::waitFor(Aws::elastiCache(), 'ReplicationGroupAvailable', [
            'ReplicationGroupId' => $this->name(),
        ], timeout: 20 * 60);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseElastiCacheTags($this->arn(), $this->tags(), $apply);
    }

    /**
     * Wait for the group to actually go — it pins its subnet/parameter/security
     * groups, which later teardown steps delete.
     */
    public function delete(): void
    {
        try {
            Aws::elastiCache()->deleteReplicationGroup([
                'ReplicationGroupId' => $this->name(),
            ]);

            Aws::waitFor(Aws::elastiCache(), 'ReplicationGroupDeleted', [
                'ReplicationGroupId' => $this->name(),
            ], timeout: 20 * 60);
        } catch (ElastiCacheException $e) {
            if ($e->getAwsErrorCode() === 'ReplicationGroupNotFoundFault') {
                return;
            }

            throw $e;
        }
    }
}
