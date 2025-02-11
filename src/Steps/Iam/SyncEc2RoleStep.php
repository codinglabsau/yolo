<?php

namespace Codinglabs\Yolo\Steps\Iam;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncEc2RoleStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        try {
            AwsResources::ec2Role();

            if (! Arr::get($options, 'dry-run')) {
                $name = Helpers::keyedResourceName(exclusive: false);

                Aws::iam()->updateRole([
                    'RoleName' => $name,
                    'Description' => 'YOLO managed EC2 role',
                ]);

                Aws::iam()->updateAssumeRolePolicy([
                    'RoleName' => $name,
                    'PolicyDocument' => json_encode(AwsResources::rolePolicyDocument()),
                ]);

                Aws::iam()->tagRole([
                    'RoleName' => $name,
                    ...Aws::tags()
                ]);

                return StepResult::SYNCED;
            }

            return StepResult::WOULD_SYNC;
        } catch (ResourceDoesNotExistException $e) {
            if (! Arr::get($options, 'dry-run')) {
                Aws::iam()->createRole([
                    'RoleName' => Helpers::keyedResourceName(exclusive: false),
                    'Description' => 'YOLO managed EC2 role',
                    'AssumeRolePolicyDocument' => json_encode(AwsResources::rolePolicyDocument()),
                    ...Aws::tags(),
                ]);

                return StepResult::CREATED;
            }

            return StepResult::WOULD_CREATE;
        }
    }
}
