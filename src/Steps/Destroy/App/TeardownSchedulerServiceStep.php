<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

/**
 * Tears down this app's scheduler ECS service.
 */
class TeardownSchedulerServiceStep extends TeardownStep
{
    protected function resource(): EcsService
    {
        return new EcsService(ServerGroup::SCHEDULER);
    }
}
