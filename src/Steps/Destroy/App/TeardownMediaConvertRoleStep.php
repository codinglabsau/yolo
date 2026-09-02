<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\MediaConvertRole;
use Codinglabs\Yolo\Steps\Sync\App\SyncMediaConvertRoleStep;

/**
 * Mirror of {@see SyncMediaConvertRoleStep}. The role's delete() detaches its
 * policies first, so this one step reverses both of the service's appSteps.
 */
class TeardownMediaConvertRoleStep extends TeardownStep
{
    protected function resource(): MediaConvertRole
    {
        return new MediaConvertRole();
    }
}
