<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\CachePatch;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\CacheTopology;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\LongRunning;

/**
 * AWS refuses both a manual promotion and a drop to zero replicas while failover
 * is on, so it goes off before either — whichever node is primary at the time.
 */
class DisableCacheFailoverStep implements LongRunning
{
    public function __invoke(array $options): StepResult
    {
        $topology = CacheTopology::read();

        if (! $topology->failoverEnabled() && ! $topology->multiAzEnabled()) {
            return StepResult::SKIPPED;
        }

        CacheTopology::waitUntilAvailable();

        Aws::elastiCache()->modifyReplicationGroup([
            'ReplicationGroupId' => CacheTopology::groupId(),
            'AutomaticFailoverEnabled' => false,
            'MultiAZEnabled' => false,
            'ApplyImmediately' => true,
        ]);

        CacheTopology::waitUntil(fn (CacheTopology $topology): bool => ! $topology->failoverEnabled() && ! $topology->multiAzEnabled(), 'disable failover');

        return StepResult::SYNCED;
    }

    public function patienceMessage(): string
    {
        return 'Disabling automatic failover and Multi-AZ';
    }
}
