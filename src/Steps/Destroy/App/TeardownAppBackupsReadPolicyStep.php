<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\AppBackupsReadPolicy;

class TeardownAppBackupsReadPolicyStep extends TeardownStep
{
    protected function resource(): AppBackupsReadPolicy
    {
        return new AppBackupsReadPolicy();
    }
}
