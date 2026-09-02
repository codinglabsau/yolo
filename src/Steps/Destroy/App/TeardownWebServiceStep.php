<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Resources\Ecs\EcsService;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

class TeardownWebServiceStep extends TeardownStep
{
    protected function resource(): EcsService
    {
        return new EcsService();
    }
}
