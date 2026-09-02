<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\EcsExecutionRole;
use Codinglabs\Yolo\Contracts\RunsOnBaseCredentials;

class TeardownEcsExecutionRoleStep extends TeardownStep implements RunsOnBaseCredentials
{
    protected function resource(): EcsExecutionRole
    {
        return new EcsExecutionRole();
    }
}
