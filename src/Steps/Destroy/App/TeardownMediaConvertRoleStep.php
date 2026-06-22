<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\MediaConvertRole;
use Codinglabs\Yolo\Steps\Sync\App\SyncMediaConvertRoleStep;

/**
 * Tears down the per-app IAM role MediaConvert assumes — the mirror of
 * {@see SyncMediaConvertRoleStep}. Its delete()
 * detaches the attached policies first, so this one step reverses both of the
 * service's appSteps. Self-skips via exists() for an app that never consumed
 * MediaConvert.
 */
class TeardownMediaConvertRoleStep extends TeardownStep
{
    protected function resource(): MediaConvertRole
    {
        return new MediaConvertRole();
    }
}
