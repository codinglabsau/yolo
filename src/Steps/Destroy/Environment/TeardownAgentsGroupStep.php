<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\Iam\AgentsGroup;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Contracts\RunsOnBaseCredentials;

class TeardownAgentsGroupStep extends TeardownStep implements RunsOnBaseCredentials
{
    protected function resource(): AgentsGroup
    {
        return new AgentsGroup();
    }
}
