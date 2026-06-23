<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\ObserverPolicy;
use Codinglabs\Yolo\Contracts\RunsOnBaseCredentials;

/**
 * Tears down the observer managed policy (detaches from every entity + deletes
 * its versions), after the role it was attached to is gone. Part of the IAM-tier
 * teardown, so it runs on base credentials (see RunsOnBaseCredentials).
 */
class TeardownObserverPolicyStep extends TeardownStep implements RunsOnBaseCredentials
{
    protected function resource(): ObserverPolicy
    {
        return new ObserverPolicy();
    }
}
