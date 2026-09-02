<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Ec2\CacheSecurityGroup;

class TeardownCacheSecurityGroupStep extends TeardownStep
{
    protected function resource(): CacheSecurityGroup
    {
        return new CacheSecurityGroup();
    }
}
