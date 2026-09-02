<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

class TeardownSchedulerServiceStep extends TeardownStep
{
    protected function resource(): EcsService
    {
        return new EcsService(ServerGroup::SCHEDULER);
    }
}
