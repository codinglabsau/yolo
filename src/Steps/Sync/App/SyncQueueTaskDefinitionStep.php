<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Enums\ServerGroup;

/**
 * Registers the standalone queue service's task definition. Wired into sync:app
 * only when a top-level `tasks.queue` block extracts the queue from the web
 * container.
 */
class SyncQueueTaskDefinitionStep extends SyncTaskDefinitionStep
{
    #[\Override]
    protected function group(): ServerGroup
    {
        return ServerGroup::QUEUE;
    }
}
