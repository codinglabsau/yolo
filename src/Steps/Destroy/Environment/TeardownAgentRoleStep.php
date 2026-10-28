<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\Iam\AgentRole;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Contracts\RunsOnBaseCredentials;

class TeardownAgentRoleStep extends TeardownStep implements RunsOnBaseCredentials
{
    protected function resource(): AgentRole
    {
        return new AgentRole();
    }
}
