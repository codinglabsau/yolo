<?php

namespace Codinglabs\Yolo\Steps\Sync\App;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Iam\MediaConvertRole;

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

        if (Arr::get($options, 'dry-run')) {
            return StepResult::WOULD_SYNC;
        }

        $roleName = (new MediaConvertRole())->name();

        foreach ($this->managedPolicies as $policyArn) {
            Aws::iam()->attachRolePolicy([
                'RoleName' => $roleName,
                'PolicyArn' => $policyArn,
            ]);
        }

        return StepResult::SYNCED;
    }
}
