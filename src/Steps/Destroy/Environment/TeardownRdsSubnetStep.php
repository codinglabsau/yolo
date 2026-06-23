<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\Rds\RdsSubnet;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

/**
 * Tears down the RDS DB subnet group, after any database that used it is gone.
 */
class TeardownRdsSubnetStep extends TeardownStep
{
    protected function resource(): RdsSubnet
    {
        return new RdsSubnet();
    }
}
