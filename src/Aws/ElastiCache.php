<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class ElastiCache
{
    public static function replicationGroup(string $id): array
    {
        return static::firstById('describeReplicationGroups', 'ReplicationGroups', 'ReplicationGroupId', $id, "Could not find ElastiCache replication group $id");
    }

    public static function cacheSubnetGroup(string $name): array
    {
        return static::firstById('describeCacheSubnetGroups', 'CacheSubnetGroups', 'CacheSubnetGroupName', $name, "Could not find ElastiCache subnet group $name");
    }

    public static function cacheParameterGroup(string $name): array
    {
        return static::firstById('describeCacheParameterGroups', 'CacheParameterGroups', 'CacheParameterGroupName', $name, "Could not find ElastiCache parameter group $name");
    }

    /**
     * @return array<string, mixed>
     */
    protected static function firstById(string $operation, string $listKey, string $idKey, string $value, string $message): array
    {
        foreach (Aws::elastiCache()->{$operation}()[$listKey] ?? [] as $item) {
            if ($item[$idKey] === $value) {
                return $item;
            }
        }

        throw new ResourceDoesNotExistException($message);
    }
}
