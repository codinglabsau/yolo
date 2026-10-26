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

    /** @return array<string, mixed> */
    public static function cacheCluster(string $id): array
    {
        return Aws::elastiCache()->describeCacheClusters(['CacheClusterId' => $id])['CacheClusters'][0];
    }

    /**
     * Service updates against one replication group, across every page. The delay
     * rides the request (as the SDK waiters do it), so a poll loop needs no sleep
     * of its own and mocked clients answer instantly. Statuses are filtered here:
     * the API only accepts a status filter alongside a service update name.
     *
     * @param  array<int, string>  $statuses  update-action statuses to keep; empty keeps all
     * @return array<int, array<string, mixed>>
     */
    public static function updateActions(string $replicationGroupId, array $statuses = [], ?string $serviceUpdateName = null, bool $nodeLevel = false, int $delaySeconds = 0): array
    {
        $actions = [];
        $marker = null;

        do {
            $page = Aws::elastiCache()->describeUpdateActions(array_filter([
                'ReplicationGroupIds' => [$replicationGroupId],
                'ServiceUpdateName' => $serviceUpdateName,
                'ShowNodeLevelUpdateStatus' => $nodeLevel,
                'Marker' => $marker,
                '@http' => $delaySeconds > 0 && $marker === null ? ['delay' => $delaySeconds * 1000] : null,
            ], fn (mixed $value): bool => $value !== null && $value !== []));

            array_push($actions, ...$page['UpdateActions'] ?? []);
            $marker = $page['Marker'] ?? null;
        } while ($marker !== null);

        return array_values(array_filter($actions, fn (array $action): bool => ($action['ServiceUpdateStatus'] ?? null) === 'available'
            && ($statuses === [] || in_array($action['UpdateActionStatus'] ?? null, $statuses, true))));
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
