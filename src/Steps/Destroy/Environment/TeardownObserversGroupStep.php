<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\ObserversGroup;
use Codinglabs\Yolo\Contracts\RunsOnBaseCredentials;

/**
 * Tears down the env observers grant group (removes members + its assume-role
 * policy, then deletes the group). Part of the IAM-tier teardown, so it runs on
 * base credentials (see RunsOnBaseCredentials).
 */
class TeardownObserversGroupStep extends TeardownStep implements RunsOnBaseCredentials
{
    protected function resource(): ObserversGroup
    {
        return new ObserversGroup();
    }
}
