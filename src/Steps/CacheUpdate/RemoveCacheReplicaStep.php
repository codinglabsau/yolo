<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\CacheUpdate;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\CacheTopology;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

/** The replica is always named: left to choose, AWS may remove the original node. */
class RemoveCacheReplicaStep implements LongRunning
{
    public function __invoke(array $options): StepResult
    {
        $topology = CacheTopology::read();
        $replica = $topology->temporaryReplica();

        if ($replica === null) {
            return StepResult::SKIPPED;
        }

        if ($topology->primary() !== CacheTopology::originalNode() || $topology->failoverEnabled()) {
            throw new IntegrityCheckException(sprintf('Refusing to remove %s: %s must be primary with failover off first.', $replica, CacheTopology::originalNode()));
        }

        CacheTopology::waitUntilAvailable();

        Aws::elastiCache()->decreaseReplicaCount([
            'ReplicationGroupId' => CacheTopology::groupId(),
            'NewReplicaCount' => 0,
            'ReplicasToRemove' => [$replica],
            'ApplyImmediately' => true,
        ]);

        CacheTopology::waitUntil(fn (CacheTopology $topology): bool => ! in_array($replica, $topology->members(), true), sprintf('remove %s', $replica));

        return StepResult::DELETED;
    }

    public function patienceMessage(): string
    {
        return 'Removing the temporary replica';
    }
}
