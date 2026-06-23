<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\Ec2\Vpc;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

/**
 * Tears down the VPC, the last of the network shell — after its subnets, route
 * table, internet gateway and security groups are gone.
 */
class TeardownVpcStep extends TeardownStep
{
    protected function resource(): Vpc
    {
        return new Vpc();
    }
}
