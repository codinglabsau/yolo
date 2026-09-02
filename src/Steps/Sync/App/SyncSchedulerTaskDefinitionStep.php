<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Enums\ServerGroup;

class SyncSchedulerTaskDefinitionStep extends SyncTaskDefinitionStep
{
    #[\Override]
    protected function group(): ServerGroup
    {
        return ServerGroup::SCHEDULER;
    }
}
