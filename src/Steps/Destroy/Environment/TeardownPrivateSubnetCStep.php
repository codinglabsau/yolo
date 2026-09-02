<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Ec2\PrivateSubnet;

class TeardownPrivateSubnetCStep extends TeardownStep
{
    protected function resource(): PrivateSubnet
    {
        return new PrivateSubnet(2);
    }
}
