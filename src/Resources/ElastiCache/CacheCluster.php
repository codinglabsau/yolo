<?php

namespace Codinglabs\Yolo\Resources\ElastiCache;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Aws\ElastiCache;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\Ec2\CacheSecurityGroup;
use Codinglabs\Yolo\Enums\ElastiCache as ElastiCacheEnum;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The shared Valkey cache: a single-node replication group (0 replicas), the
 * cheapest topology that still uses the modern, replica-ready construct.
 * Functionally a standard single instance — auto-failover and Multi-AZ are off.
 * Scaling is a manual vertical resize; HA, if ever needed, is an in-place add.
 */
class CacheCluster implements Resource
{
    use ResolvesTags;

    public const ENGINE = 'valkey';

    // Pinned as a matched pair — a custom parameter group forces a family that
    // is coupled to the engine major. The only pinned version in YOLO; revisit
    // on a Valkey engine bump.
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

    /**
     * The primary endpoint address (cluster mode disabled → one node group).
     * Read at build time to populate REDIS_HOST.
     */
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
            // Valkey requires this explicitly once any encryption setting is touched — it has no
            // default. TLS in-transit is deferred; the cache is SG-locked to the task SG on 6379, so
            // plaintext stays inside the VPC.
            'TransitEncryptionEnabled' => false,
            'Port' => self::PORT,
            'CacheSubnetGroupName' => (new CacheSubnetGroup())->name(),
            'CacheParameterGroupName' => (new CacheParameterGroup())->name(),
            'SecurityGroupIds' => [(new CacheSecurityGroup())->arn()],
            ...Aws::tags($this->tags()),
        ]);

        // A fresh single-node Valkey cluster routinely takes longer than the
        // SDK's 10-minute default, so wait up to 20 minutes — the heartbeat
        // keeps the (LongRunning) sync step's progress bar moving meanwhile.
        Aws::waitFor(Aws::elastiCache(), 'ReplicationGroupAvailable', [
            'ReplicationGroupId' => $this->name(),
        ], timeout: 20 * 60);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseElastiCacheTags($this->arn(), $this->tags(), $apply);
    }
}
