<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;

/**
 * Tears down the environment's application load balancer. Its delete() lifts the
 * pinned deletion-protection first; the listeners are deleted ahead of it.
 */
class TeardownLoadBalancerStep extends TeardownStep
{
    protected function resource(): LoadBalancer
    {
        return new LoadBalancer();
    }
}
