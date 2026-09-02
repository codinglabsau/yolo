<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\Iam\AdminRole;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Contracts\RunsOnBaseCredentials;

/**
 * The IAM tier (groups, roles, policies) runs on base credentials: this is the
 * role the run assumed, so it can't be deleted under the tier it's
 * authenticated as.
 */
class TeardownAdminRoleStep extends TeardownStep implements RunsOnBaseCredentials
{
    protected function resource(): AdminRole
    {
        return new AdminRole();
    }
}
