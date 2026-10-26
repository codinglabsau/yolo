<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\CacheUpdate;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\CacheTopology;
use Codinglabs\Yolo\Aws\ElastiCache;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

/**
 * `increase-replica-count`, not `create-cache-cluster`: the latter rejects the
 * Valkey engine outright. The replica launches already carrying every pending
 * update, which is what makes it a safe failover target.
 */
class AddCacheReplicaStep implements LongRunning
{
    public function __invoke(array $options): StepResult
    {
        $topology = CacheTopology::read();
        $replica = $topology->temporaryReplica();
        $result = StepResult::SKIPPED;

        if ($replica === null) {
            CacheTopology::waitUntilAvailable();

            Aws::elastiCache()->increaseReplicaCount([
                'ReplicationGroupId' => CacheTopology::groupId(),
                'ReplicaConfiguration' => [[
                    'NodeGroupId' => '0001',
                    'NewReplicaCount' => 1,
                    // One zone per node, the primary's first.
                    'PreferredAvailabilityZones' => static::zones(),
                ]],
                'ApplyImmediately' => true,
            ]);

            $replica = CacheTopology::read()->temporaryReplica()
                ?? throw new IntegrityCheckException(sprintf('%s accepted the new replica but lists no second member.', CacheTopology::groupId()));

            $result = StepResult::CREATED;
        }

        // The group reports `available` while its new member is still creating,
        // so only the node's own status says the replica is ready.
        Aws::waitFor(Aws::elastiCache(), 'CacheClusterAvailable', [
            'CacheClusterId' => $replica,
        ], timeout: CacheTopology::WAIT_TIMEOUT_SECONDS);

        CacheTopology::waitUntilAvailable();

        return $result;
    }

    /**
     * The primary's zone, then another its subnet group spans, so a failover
     * moves the cache across zones rather than onto the same hardware pool.
     *
     * @return array{0: string, 1: string}
     */
    public static function zones(): array
    {
        $node = ElastiCache::cacheCluster(CacheTopology::originalNode());
        $primaryZone = (string) $node['PreferredAvailabilityZone'];

        $other = collect(ElastiCache::cacheSubnetGroup((string) $node['CacheSubnetGroupName'])['Subnets'] ?? [])
            ->map(fn (array $subnet): string => (string) ($subnet['SubnetAvailabilityZone']['Name'] ?? ''))
            ->reject(fn (string $zone): bool => $zone === '' || $zone === $primaryZone)
            ->unique()
            ->sort()
            ->first();

        if ($other === null) {
            throw new IntegrityCheckException(sprintf('The cache subnet group only spans %s — a replica needs a second availability zone.', $primaryZone));
        }

        return [$primaryZone, $other];
    }

    public function patienceMessage(): string
    {
        return 'Launching a temporary replica — ElastiCache usually takes around 10 minutes';
    }
}
