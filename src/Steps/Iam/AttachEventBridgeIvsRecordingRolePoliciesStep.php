<?php

namespace Codinglabs\Yolo\Steps\Iam;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;

class AttachEventBridgeIvsRecordingRolePoliciesStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        if (! Manifest::ivsRecordingEnabled()) {
            return StepResult::SKIPPED;
        }

        if (! Arr::get($options, 'dry-run')) {
            $role = AwsResources::eventBridgeIvsRecordingRole();

            Aws::iam()->putRolePolicy([
                'RoleName' => $role['RoleName'],
                'PolicyName' => 'InvokeApiDestination',
                'PolicyDocument' => json_encode([
                    'Version' => '2012-10-17',
                    'Statement' => [[
                        'Effect' => 'Allow',
                        'Action' => ['events:InvokeApiDestination'],
                        'Resource' => '*',
                    ]],
                ]),
            ]);

            return StepResult::SYNCED;
        }

        return StepResult::WOULD_SYNC;
    }
}
