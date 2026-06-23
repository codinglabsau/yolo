<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\Iam\ObserverRole;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Contracts\RunsOnBaseCredentials;

/**
 * Tears down the observer tier role, after its grant group is gone. Part of the
 * IAM-tier teardown, so it runs on base credentials (see RunsOnBaseCredentials).
 */
class TeardownObserverRoleStep extends TeardownStep implements RunsOnBaseCredentials
{
    protected function resource(): ObserverRole
    {
        return new ObserverRole();
    }
}
