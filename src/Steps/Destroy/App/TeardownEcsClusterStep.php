<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Resources\Ecs\EcsCluster;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

/**
 * Tears down this app's ECS cluster. LongRunning — the delete force-drains any
 * remaining service, then retries the cluster delete until the last task stops
 * over the graceful-drain window (AWS refuses DeleteCluster while tasks are
 * active, even once the service itself is gone).
 */
class TeardownEcsClusterStep extends TeardownStep implements LongRunning
{
    protected function resource(): EcsCluster
    {
        return new EcsCluster();
    }

    public function patienceMessage(): string
    {
        return 'Draining the ECS service(s) before removing the cluster — usually under a minute.';
    }
}
