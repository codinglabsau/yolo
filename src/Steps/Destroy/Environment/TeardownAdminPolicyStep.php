<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\Iam\AdminPolicy;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Contracts\RunsOnBaseCredentials;

class TeardownAdminPolicyStep extends TeardownStep implements RunsOnBaseCredentials
{
    protected function resource(): AdminPolicy
    {
        return new AdminPolicy();
    }
}
