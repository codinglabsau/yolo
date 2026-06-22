<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\Ec2\RouteTable;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

/**
 * Tears down the public route table, after the subnets it associated are gone.
 */
class TeardownRouteTableStep extends TeardownStep
{
    protected function resource(): RouteTable
    {
        return new RouteTable();
    }
}
