<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\ObserverPolicy;

/**
 * Tears down the observer managed policy (detaches from every entity + deletes
 * its versions), after the role it was attached to is gone.
 */
class TeardownObserverPolicyStep extends TeardownStep
{
    protected function resource(): ObserverPolicy
    {
        return new ObserverPolicy();
    }
}
