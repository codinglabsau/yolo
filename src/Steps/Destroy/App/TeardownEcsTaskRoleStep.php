<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Resources\Iam\EcsTaskRole;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

class TeardownEcsTaskRoleStep extends TeardownStep
{
    protected function resource(): EcsTaskRole
    {
        return new EcsTaskRole();
    }
}
