<?php

namespace Codinglabs\Yolo\Steps\Iam;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\DeployerRole;
use Codinglabs\Yolo\Resources\Iam\DeployerPolicy;

class AttachDeployerRolePoliciesStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (! Manifest::has('deployer')) {
            return StepResult::SKIPPED;
        }

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_SYNC;
        }

        Aws::iam()->attachRolePolicy([
            'RoleName' => (new DeployerRole())->name(),
            'PolicyArn' => (new DeployerPolicy())->arn(),
        ]);

        return StepResult::SYNCED;
    }
}
