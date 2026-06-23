<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\ElastiCache\CacheCluster;

/**
 * Tears down the shared Valkey cache (a single-node replication group). Its
 * delete() waits for the deletion to finish, so the subnet/parameter groups + the
 * security group it pins can be removed by the steps that follow.
 */
class TeardownCacheClusterStep extends TeardownStep implements LongRunning
{
    public function patienceMessage(): string
    {
        return 'Deleting the Valkey cache — this can take a few minutes.';
    }

    protected function resource(): CacheCluster
    {
        return new CacheCluster();
    }
}
