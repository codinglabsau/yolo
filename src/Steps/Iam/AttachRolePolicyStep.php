<?php

namespace Codinglabs\Yolo\Steps\Iam;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

class AttachRolePolicyStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (! Arr::get($options, 'dry-run')) {
            $policy = AwsResources::ec2Policy();
            $role = AwsResources::ec2Role();

            Aws::iam()->attachRolePolicy([
                'PolicyArn' => $policy['Arn'],
                'RoleName' => $role['RoleName'],
            ]);

            return StepResult::SYNCED;
        }

        return StepResult::WOULD_SYNC;
    }
}
