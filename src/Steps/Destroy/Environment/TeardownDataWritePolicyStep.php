<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\DataWritePolicy;
use Codinglabs\Yolo\Contracts\RunsOnBaseCredentials;

class TeardownDataWritePolicyStep extends TeardownStep implements RunsOnBaseCredentials
{
    protected function resource(): DataWritePolicy
    {
        return new DataWritePolicy();
    }
}
