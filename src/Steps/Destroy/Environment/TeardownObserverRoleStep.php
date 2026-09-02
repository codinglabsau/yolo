<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\Iam\ObserverRole;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Contracts\RunsOnBaseCredentials;

class TeardownObserverRoleStep extends TeardownStep implements RunsOnBaseCredentials
{
    protected function resource(): ObserverRole
    {
        return new ObserverRole();
    }
}
