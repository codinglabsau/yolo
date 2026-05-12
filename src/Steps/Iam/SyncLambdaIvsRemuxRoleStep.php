<?php

namespace Codinglabs\Yolo\Steps\Iam;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncLambdaIvsRemuxRoleStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (! Manifest::ivsRecordingEnabled()) {
            return StepResult::SKIPPED;
        }

        if (! Manifest::ivsRealtimeRemuxWebhookUrl()) {
            return StepResult::SKIPPED;
        }

        if (! Manifest::ivsRealtimeMainBucket()) {
            return StepResult::SKIPPED;
        }

        try {
            AwsResources::lambdaIvsRemuxRole();

            if (! Arr::get($options, 'dry-run')) {
                $name = Helpers::keyedResourceName(Iam::LAMBDA_IVS_REMUX_ROLE);

                Aws::iam()->updateRole([
                    'RoleName' => $name,
                    'Description' => 'YOLO managed Lambda role for IVS Real-Time remux',
                ]);

                Aws::iam()->updateAssumeRolePolicy([
                    'RoleName' => $name,
                    'PolicyDocument' => json_encode(AwsResources::lambdaIvsRemuxPolicyDocument()),
                ]);

                Aws::iam()->tagRole([
                    'RoleName' => $name,
                    ...Aws::tags(),
                ]);

                return StepResult::SYNCED;
            }

            return StepResult::WOULD_SYNC;
        } catch (ResourceDoesNotExistException) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::iam()->createRole([
                    'RoleName' => Helpers::keyedResourceName(Iam::LAMBDA_IVS_REMUX_ROLE),
                    'Description' => 'YOLO managed Lambda role for IVS Real-Time remux',
                    'AssumeRolePolicyDocument' => json_encode(AwsResources::lambdaIvsRemuxPolicyDocument()),
                    ...Aws::tags(),
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
