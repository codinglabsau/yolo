<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\ObserversGroup;
use Codinglabs\Yolo\Contracts\RunsOnBaseCredentials;

class TeardownObserversGroupStep extends TeardownStep implements RunsOnBaseCredentials
{
    protected function resource(): ObserversGroup
    {
        return new ObserversGroup();
    }
}
