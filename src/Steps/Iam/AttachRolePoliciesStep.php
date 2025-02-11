<?php

namespace Codinglabs\Yolo\Steps\Iam;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

class AttachRolePoliciesStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (! Arr::get($options, 'dry-run')) {
            $policy = AwsResources::ec2Policy();
            $role = AwsResources::ec2Role();

            Aws::iam()->attachRolePolicy([
                'RoleName' => $role['RoleName'],
                'PolicyArn' => $policy['Arn'],
            ]);

            Aws::iam()->attachRolePolicy([
                'RoleName' => $role['RoleName'],
                'PolicyArn' => 'arn:aws:iam::aws:policy/AmazonElasticTranscoder_JobsSubmitter',
            ]);

            return StepResult::SYNCED;
        }

        return StepResult::WOULD_SYNC;
    }
}
