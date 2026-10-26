<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\CacheUpdate;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\CacheTopology;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

/** AWS refuses to drop to zero replicas while failover is on, so it goes first. */
class DisableCacheFailoverStep implements LongRunning
{
    public function __invoke(array $options): StepResult
    {
        $topology = CacheTopology::read();

        if (! $topology->failoverEnabled() && ! $topology->multiAzEnabled()) {
            return StepResult::SKIPPED;
        }

        if ($topology->primary() !== CacheTopology::originalNode()) {
            throw new IntegrityCheckException(sprintf('%s is not the primary — refusing to turn failover off while the temporary replica holds the primary role.', CacheTopology::originalNode()));
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
