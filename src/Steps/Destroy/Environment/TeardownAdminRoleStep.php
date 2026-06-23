<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\Iam\AdminRole;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Contracts\RunsOnBaseCredentials;

/**
 * Tears down the admin tier role, after its grant group is gone. Runs on base
 * credentials — this is the role the run assumed, so it can't be deleted under
 * the tier it's authenticated as (see RunsOnBaseCredentials).
 */
class TeardownAdminRoleStep extends TeardownStep implements RunsOnBaseCredentials
{
    protected function resource(): AdminRole
    {
        return new AdminRole();
    }
}
