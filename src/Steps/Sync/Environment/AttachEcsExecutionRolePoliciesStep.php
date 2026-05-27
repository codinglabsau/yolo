<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\EcsExecutionRole;

class AttachEcsExecutionRolePoliciesStep implements Step
{
    /**
     * AWS-managed policy granting ECR image pull + CloudWatch Logs write — the
     * baseline an ECS agent needs to launch a Fargate task.
     */
    public const POLICY_ARN = 'arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy';

    public function __invoke(array $options): StepResult
    {
        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_SYNC;
        }

        Aws::iam()->attachRolePolicy([
            'RoleName' => (new EcsExecutionRole())->name(),
            'PolicyArn' => static::POLICY_ARN,
        ]);

        return StepResult::SYNCED;
    }
}
