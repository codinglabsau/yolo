<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\AppDataWritePolicy;

class TeardownAppDataWritePolicyStep extends TeardownStep
{
    protected function resource(): AppDataWritePolicy
    {
        return new AppDataWritePolicy();
    }
}
