<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\AppDeveloperRole;

class TeardownAppDeveloperRoleStep extends TeardownStep
{
    protected function resource(): AppDeveloperRole
    {
        return new AppDeveloperRole();
    }
}
