<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\DevelopersGroup;
use Codinglabs\Yolo\Contracts\RunsOnBaseCredentials;

class TeardownDevelopersGroupStep extends TeardownStep implements RunsOnBaseCredentials
{
    protected function resource(): DevelopersGroup
    {
        return new DevelopersGroup();
    }
}
