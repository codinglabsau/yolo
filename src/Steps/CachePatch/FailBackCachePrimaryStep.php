<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\CachePatch;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\CacheTopology;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

/**
 * Patching usually fails over to the replica; the original node must end as
 * primary. Runs after failover is off, the only state AWS accepts a promotion in.
 */
class FailBackCachePrimaryStep implements LongRunning
{
    public function __invoke(array $options): StepResult
    {
        if (CacheTopology::read()->primary() === CacheTopology::originalNode()) {
            return StepResult::SKIPPED;
        }

        if (CacheTopology::read()->failoverEnabled()) {
            throw new IntegrityCheckException(sprintf('Refusing to promote %s while automatic failover is on — AWS rejects a manual promotion until it is off.', CacheTopology::originalNode()));
        }

        CacheTopology::waitUntilAvailable();

        Aws::elastiCache()->modifyReplicationGroup([
            'ReplicationGroupId' => CacheTopology::groupId(),
            'PrimaryClusterId' => CacheTopology::originalNode(),
            'ApplyImmediately' => true,
        ]);

        CacheTopology::waitUntil(fn (CacheTopology $topology): bool => $topology->primary() === CacheTopology::originalNode(), sprintf('promote %s', CacheTopology::originalNode()));

        return StepResult::SYNCED;
    }

    public function patienceMessage(): string
    {
        return sprintf('Promoting %s back to primary', CacheTopology::originalNode());
    }
}
