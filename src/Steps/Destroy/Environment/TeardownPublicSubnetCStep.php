<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\Ec2\PublicSubnet;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

/**
 * Tears down the third public subnet (AZ index 2), after everything in it — the
 * ALB and Fargate ENIs — is gone.
 */
class TeardownPublicSubnetCStep extends TeardownStep
{
    protected function resource(): PublicSubnet
    {
        return new PublicSubnet(2);
    }
}
