<?php

namespace Codinglabs\Yolo\Steps\Iam;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

class AttachEcsTaskRolePoliciesStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_SYNC;
        }

        Aws::iam()->attachRolePolicy([
            'RoleName' => AwsResources::ecsTaskRole()['RoleName'],
            'PolicyArn' => AwsResources::ecsTaskPolicy()['Arn'],
        ]);

        return StepResult::SYNCED;
    }
}
