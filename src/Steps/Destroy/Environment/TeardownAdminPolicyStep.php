<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\Iam\AdminPolicy;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;

/**
 * Tears down the admin managed policy (detaches from every entity + deletes its
 * versions), after the role it was attached to is gone.
 */
class TeardownAdminPolicyStep extends TeardownStep
{
    protected function resource(): AdminPolicy
    {
        return new AdminPolicy();
    }
}
