<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

/**
 * Tears down this app's ECS cluster.
 */
class TeardownEcsClusterStep extends TeardownStep
{
    protected function resource(): EcsCluster
    {
        return new EcsCluster();
    }
}
