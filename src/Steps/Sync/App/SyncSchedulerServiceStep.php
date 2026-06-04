<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Enums\ServerGroup;

/**
 * Syncs the standalone scheduler ECS service (a pinned singleton). Wired into
 * sync:app only when a top-level `tasks.scheduler` block extracts the scheduler
 * from the web container.
 */
class SyncSchedulerServiceStep extends SyncEcsServiceStep
{
    protected function group(): ServerGroup
    {
        return ServerGroup::SCHEDULER;
    }
}
