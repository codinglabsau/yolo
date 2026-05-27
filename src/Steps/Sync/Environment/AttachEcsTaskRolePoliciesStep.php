<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\EcsTaskRole;
use Codinglabs\Yolo\Resources\Iam\EcsTaskPolicy;

class AttachEcsTaskRolePoliciesStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_SYNC;
        }

        Aws::iam()->attachRolePolicy([
            'RoleName' => (new EcsTaskRole())->name(),
            'PolicyArn' => (new EcsTaskPolicy())->arn(),
        ]);

        return StepResult::SYNCED;
    }
}
