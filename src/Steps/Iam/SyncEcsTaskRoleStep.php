<?php

namespace Codinglabs\Yolo\Steps\Iam;

use Codinglabs\Yolo\Aws;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\AwsResources;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

class SyncEcsTaskRoleStep implements Step
{
    public function __invoke(array $options): StepResult
    {
        $document = json_encode(AwsResources::ecsTaskAssumeRolePolicyDocument());

        try {
            $role = AwsResources::ecsTaskRole();

            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_SYNC;
            }

            Aws::iam()->updateAssumeRolePolicy([
                'RoleName' => $role['RoleName'],
                'PolicyDocument' => $document,
            ]);

            Aws::iam()->tagRole([
                'RoleName' => $role['RoleName'],
                ...Aws::tags(),
            ]);

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_CREATE;
            }

            Aws::iam()->createRole([
                'RoleName' => Helpers::keyedResourceName(Iam::ECS_TASK_ROLE, exclusive: false),
                'Description' => 'YOLO managed ECS task role — shared default across all apps in this environment',
                'AssumeRolePolicyDocument' => $document,
                ...Aws::tags(),
            ]);

            return StepResult::CREATED;
        }
    }
}
