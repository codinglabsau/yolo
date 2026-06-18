<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy\App;

use Codinglabs\Yolo\Steps\Destroy\TeardownStep;
use Codinglabs\Yolo\Resources\Iam\EcsTaskPolicy;

/**
 * Tears down this app's ECS task IAM policy.
 */
class TeardownEcsTaskPolicyStep extends TeardownStep
{
    protected function resource(): EcsTaskPolicy
    {
        return new EcsTaskPolicy();
    }
}
