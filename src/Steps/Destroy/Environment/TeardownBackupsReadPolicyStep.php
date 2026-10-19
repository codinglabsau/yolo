<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Contracts\RunsOnBaseCredentials;
use Codinglabs\Yolo\Resources\Iam\BackupsReadPolicy;

class TeardownBackupsReadPolicyStep extends TeardownStep implements RunsOnBaseCredentials
{
    protected function resource(): BackupsReadPolicy
    {
        return new BackupsReadPolicy();
    }
}
