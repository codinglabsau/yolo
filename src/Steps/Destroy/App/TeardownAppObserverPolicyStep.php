<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\AppObserverPolicy;

/**
 * Tears down this app's observer IAM policy.
 */
class TeardownAppObserverPolicyStep extends TeardownStep
{
    protected function resource(): AppObserverPolicy
    {
        return new AppObserverPolicy();
    }
}
