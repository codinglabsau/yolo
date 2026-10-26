<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\CacheUpdate;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\CacheTopology;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\LongRunning;

/**
 * Failover is what lets AWS patch the primary by promoting the replica first. With
 * nothing pending, a resumed run is past the updates and only tearing down, so
 * failover is left alone.
 */
class EnableCacheFailoverStep implements LongRunning
{
    public function __invoke(array $options): StepResult
    {
        $topology = CacheTopology::read();

        if (CacheTopology::pendingUpdates() === [] || ($topology->failoverEnabled() && $topology->multiAzEnabled())) {
            return StepResult::SKIPPED;
        }

        CacheTopology::waitUntilAvailable();

        Aws::elastiCache()->modifyReplicationGroup([
            'ReplicationGroupId' => CacheTopology::groupId(),
            'AutomaticFailoverEnabled' => true,
            'MultiAZEnabled' => true,
            'ApplyImmediately' => true,
        ]);

        CacheTopology::waitUntil(fn (CacheTopology $topology): bool => ($topology->group['AutomaticFailover'] ?? null) === 'enabled' && $topology->multiAzEnabled(), 'enable failover');

        return StepResult::SYNCED;
    }

    public function patienceMessage(): string
    {
        return 'Enabling automatic failover and Multi-AZ';
    }
}
