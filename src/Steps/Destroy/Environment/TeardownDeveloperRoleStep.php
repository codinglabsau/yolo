<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\DeveloperRole;
use Codinglabs\Yolo\Contracts\RunsOnBaseCredentials;

class TeardownDeveloperRoleStep extends TeardownStep implements RunsOnBaseCredentials
{
    protected function resource(): DeveloperRole
    {
        return new DeveloperRole();
    }
}
