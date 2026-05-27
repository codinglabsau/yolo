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

    /**
     * AWS-managed policy granting ECR image pull + CloudWatch Logs write — the
     * baseline an ECS agent needs to launch a Fargate task.
     */
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
