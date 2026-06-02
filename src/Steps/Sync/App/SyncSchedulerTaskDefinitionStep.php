<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Enums\ServerGroup;

/**
 * Registers the standalone scheduler service's task definition. Wired into
 * sync:app only when a top-level `tasks.scheduler` block extracts the scheduler
 * from the web container.
 */
class SyncSchedulerTaskDefinitionStep extends SyncTaskDefinitionStep
{
    protected function group(): ServerGroup
    {
        return ServerGroup::SCHEDULER;
    }
}
