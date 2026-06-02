<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Enums\ServerGroup;

/**
 * Syncs the standalone queue ECS service. Wired into sync:app only when a
 * top-level `tasks.queue` block extracts the queue from the web container.
 */
class SyncQueueServiceStep extends SyncEcsServiceStep
{
    protected function group(): ServerGroup
    {
        return ServerGroup::QUEUE;
    }
}
