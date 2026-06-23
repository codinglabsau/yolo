<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\Iam\AdminPolicy;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Contracts\RunsOnBaseCredentials;

/**
 * Tears down the admin managed policy (detaches from every entity + deletes its
 * versions), after the role it was attached to is gone. Runs on base credentials
 * — this is the policy that grants the run its permissions, so it can't be deleted
 * under the tier it's authenticated as.
 */
class TeardownAdminPolicyStep extends TeardownStep implements RunsOnBaseCredentials
{
    protected function resource(): AdminPolicy
    {
        return new AdminPolicy();
    }
}
