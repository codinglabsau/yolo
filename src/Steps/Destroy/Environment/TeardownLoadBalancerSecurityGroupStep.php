<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Ec2\LoadBalancerSecurityGroup;

/**
 * Tears down the load balancer's security group, after the load balancer that
 * used it is gone (AWS refuses to delete a security group still in use).
 */
class TeardownLoadBalancerSecurityGroupStep extends TeardownStep
{
    protected function resource(): LoadBalancerSecurityGroup
    {
        return new LoadBalancerSecurityGroup();
    }
}
