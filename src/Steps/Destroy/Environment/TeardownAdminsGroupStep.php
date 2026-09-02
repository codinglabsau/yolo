<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\Iam\AdminsGroup;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Contracts\RunsOnBaseCredentials;

class TeardownAdminsGroupStep extends TeardownStep implements RunsOnBaseCredentials
{
    protected function resource(): AdminsGroup
    {
        return new AdminsGroup();
    }
}
