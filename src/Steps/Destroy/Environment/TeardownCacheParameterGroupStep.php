<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\ElastiCache\CacheParameterGroup;

/**
 * Tears down the Valkey cache parameter group, after the cache that used it is gone.
 */
class TeardownCacheParameterGroupStep extends TeardownStep
{
    protected function resource(): CacheParameterGroup
    {
        return new CacheParameterGroup();
    }
}
