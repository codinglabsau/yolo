<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\ObserverPolicy;
use Codinglabs\Yolo\Contracts\RunsOnBaseCredentials;

class TeardownObserverPolicyStep extends TeardownStep implements RunsOnBaseCredentials
{
    protected function resource(): ObserverPolicy
    {
        return new ObserverPolicy();
    }
}
