<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Concerns\AttachesRolePolicies;
use Codinglabs\Yolo\Resources\Iam\EcsExecutionRole;

class AttachEcsExecutionRolePoliciesStep implements Step
{
    use AttachesRolePolicies;

    public const POLICY_ARN = 'arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy';

    public function __invoke(array $options): StepResult
    {
        return $this->attachRolePolicies(
            (new EcsExecutionRole())->name(),
            [static::POLICY_ARN],
            (bool) Arr::get($options, 'dry-run'),
        );
    }
}
