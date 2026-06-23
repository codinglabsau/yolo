<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\Environment;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\ObserversGroup;

/**
 * Tears down the env observers grant group (removes members + its assume-role
 * policy, then deletes the group).
 */
class TeardownObserversGroupStep extends TeardownStep
{
    protected function resource(): ObserversGroup
    {
        return new ObserversGroup();
    }
}
