<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\AppDevelopersGroup;

class TeardownAppDevelopersGroupStep extends TeardownStep
{
    protected function resource(): AppDevelopersGroup
    {
        return new AppDevelopersGroup();
    }
}
