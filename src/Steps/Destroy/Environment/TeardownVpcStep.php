<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\Ec2\Vpc;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

class TeardownVpcStep extends TeardownStep
{
    protected function resource(): Vpc
    {
        return new Vpc();
    }
}
