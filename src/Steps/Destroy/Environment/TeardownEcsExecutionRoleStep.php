<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\EcsExecutionRole;
use Codinglabs\Yolo\Contracts\RunsOnBaseCredentials;

/**
 * Tears down the shared ECS execution role, after every task (apps + env service
 * nodes) that assumed it is gone. Part of the IAM-tier teardown, so it runs on
 * base credentials (see RunsOnBaseCredentials).
 */
class TeardownEcsExecutionRoleStep extends TeardownStep implements RunsOnBaseCredentials
{
    protected function resource(): EcsExecutionRole
    {
        return new EcsExecutionRole();
    }
}
