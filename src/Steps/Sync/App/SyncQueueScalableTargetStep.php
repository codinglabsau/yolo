<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Enums\ServerGroup;

/**
 * Registers and reconciles the standalone queue service's scalable target — the
 * 0→N capacity bounds (`tasks.queue.min`/`max`, min default 0) the queue's
 * policies move within. Wired into sync:app only when tasks.queue extracts the
 * queue into its own service.
 */
class SyncQueueScalableTargetStep extends SyncScalableTargetStep
{
    #[\Override]
    protected function group(): ServerGroup
    {
        return ServerGroup::QUEUE;
    }
}
