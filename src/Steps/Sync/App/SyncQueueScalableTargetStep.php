<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Enums\ServerGroup;

class SyncQueueScalableTargetStep extends SyncScalableTargetStep
{
    #[\Override]
    protected function group(): ServerGroup
    {
        return ServerGroup::QUEUE;
    }
}
