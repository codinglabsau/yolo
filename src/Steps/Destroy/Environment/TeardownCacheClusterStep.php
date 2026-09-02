<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\ElastiCache\CacheCluster;

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
