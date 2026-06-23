<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\Ec2\PublicSubnet;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

/**
 * Tears down the second public subnet (AZ index 1), after everything in it — the
 * ALB and Fargate ENIs — is gone.
 */
class TeardownPublicSubnetBStep extends TeardownStep
{
    protected function resource(): PublicSubnet
    {
        return new PublicSubnet(1);
    }
}
