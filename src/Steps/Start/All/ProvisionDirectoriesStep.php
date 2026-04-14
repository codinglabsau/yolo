<?php

namespace Codinglabs\Yolo\Steps\Start\All;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Contracts\RunsOnAws;
use Codinglabs\Yolo\Contracts\HasSubSteps;

class ProvisionDirectoriesStep implements HasSubSteps, RunsOnAws
{
    public function __invoke(): array
    {
        return [
            sprintf('mkdir -p %s', Paths::yoloDir()),
            sprintf('mkdir -p %s', Paths::logDir()),
        ];
    }
}
