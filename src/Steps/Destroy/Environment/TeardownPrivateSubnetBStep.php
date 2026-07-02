<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Ec2\PrivateSubnet;

/**
 * Tears down the second private subnet (AZ index 1), only ever reached once no
 * database remains in the VPC (the network reclaim gate).
 */
class TeardownPrivateSubnetBStep extends TeardownStep
{
    protected function resource(): PrivateSubnet
    {
        return new PrivateSubnet(1);
    }
}
