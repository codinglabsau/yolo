<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\Ec2\PublicSubnet;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

class TeardownPublicSubnetBStep extends TeardownStep
{
    protected function resource(): PublicSubnet
    {
        return new PublicSubnet(1);
    }
}
