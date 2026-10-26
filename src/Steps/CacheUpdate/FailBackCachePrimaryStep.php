<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\CacheUpdate;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\CacheTopology;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\LongRunning;

/** Patching usually fails over to the replica; the original node must end as primary. */
class FailBackCachePrimaryStep implements LongRunning
{
    public function __invoke(array $options): StepResult
    {
        if (CacheTopology::read()->primary() === CacheTopology::originalNode()) {
            return StepResult::SKIPPED;
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
