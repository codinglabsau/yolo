<?php

namespace Codinglabs\Yolo\Steps\Iam;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

class AttachMediaConvertRolePoliciesStep implements Step
{
    protected array $managedPolicies = [
        'arn:aws:iam::aws:policy/AmazonAPIGatewayInvokeFullAccess',
        'arn:aws:iam::aws:policy/AmazonS3FullAccess',
    ];

    public function __invoke(array $options): StepResult
    {
        if (! Manifest::get('aws.mediaconvert')) {
            return StepResult::SKIPPED;
        }

        if (! Arr::get($options, 'dry-run')) {
            $role = AwsResources::mediaConvertRole();

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
