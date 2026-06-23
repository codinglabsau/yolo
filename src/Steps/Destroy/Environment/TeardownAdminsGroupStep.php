<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Resources\Iam\AdminsGroup;
use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Contracts\RunsOnBaseCredentials;

/**
 * Tears down the env admins grant group (removes members + its assume-role
 * policy, then deletes the group). Part of the IAM-tier teardown, so it runs on
 * base credentials (see RunsOnBaseCredentials).
 */
class TeardownAdminsGroupStep extends TeardownStep implements RunsOnBaseCredentials
{
    protected function resource(): AdminsGroup
    {
        return new AdminsGroup();
    }
}
