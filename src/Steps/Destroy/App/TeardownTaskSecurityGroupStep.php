<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Ec2\EcsTaskSecurityGroup;

/**
 * Tears down this app's ECS task security group.
 */
class TeardownTaskSecurityGroupStep extends TeardownStep
{
    protected function resource(): EcsTaskSecurityGroup
    {
        return new EcsTaskSecurityGroup();
    }
}
