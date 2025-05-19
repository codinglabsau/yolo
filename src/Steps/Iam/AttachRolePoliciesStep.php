<?php

namespace Codinglabs\Yolo\Steps\Iam;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

class AttachRolePoliciesStep implements Step
{
    protected array $managedPolicies = [
        'arn:aws:iam::aws:policy/AmazonElasticTranscoder_JobsSubmitter',
        'arn:aws:iam::aws:policy/IVSFullAccess',
    ];

    public function __invoke(array $options): StepResult
    {
        if (! Arr::get($options, 'dry-run')) {
            $policy = AwsResources::ec2Policy();
            $role = AwsResources::ec2Role();

            Aws::iam()->attachRolePolicy([
                'RoleName' => $role['RoleName'],
                'PolicyArn' => $policy['Arn'],
            ]);

            foreach ($this->managedPolicies as $policyArn) {
                Aws::iam()->attachRolePolicy([
                    'RoleName' => $role['RoleName'],
                    'PolicyArn' => $policyArn,
                ]);
            }

            return StepResult::SYNCED;
        }

        return StepResult::WOULD_SYNC;
    }
}
