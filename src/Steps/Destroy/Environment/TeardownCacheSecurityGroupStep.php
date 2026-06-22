<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Ec2\CacheSecurityGroup;

/**
 * Tears down the Valkey cache security group, after the cache that used it is gone
 * and apps have revoked their 6379 ingress (destroy:app + the all-apps-gone guard).
 */
class TeardownCacheSecurityGroupStep extends TeardownStep
{
    protected function resource(): CacheSecurityGroup
    {
        return new CacheSecurityGroup();
    }
}
