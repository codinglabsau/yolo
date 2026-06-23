<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\ElastiCache\CacheSubnetGroup;

/**
 * Tears down the Valkey cache subnet group, after the cache that used it is gone.
 */
class TeardownCacheSubnetGroupStep extends TeardownStep
{
    protected function resource(): CacheSubnetGroup
    {
        return new CacheSubnetGroup();
    }
}
