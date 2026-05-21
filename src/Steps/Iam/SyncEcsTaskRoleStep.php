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

            $this->ensurePolicyAttached($role['RoleName']);

            return StepResult::SYNCED;
        } catch (ResourceDoesNotExistException) {
            if (Arr::get($options, 'dry-run')) {
                return StepResult::WOULD_CREATE;
            }

            $name = Helpers::keyedResourceName(Iam::ECS_TASK_ROLE, exclusive: false);

            Aws::iam()->createRole([
                'RoleName' => $name,
                'Description' => 'YOLO managed ECS task role — shared default across all apps in this environment',
                'AssumeRolePolicyDocument' => $document,
                ...Aws::tags(),
            ]);

            $this->ensurePolicyAttached($name);

            return StepResult::CREATED;
        }
    }

    protected function ensurePolicyAttached(string $roleName): void
    {
        $policyArn = AwsResources::ecsTaskPolicy()['Arn'];

        $attached = collect(Aws::iam()->listAttachedRolePolicies([
            'RoleName' => $roleName,
        ])['AttachedPolicies'])->contains(fn (array $policy) => $policy['PolicyArn'] === $policyArn);

        if (! $attached) {
            Aws::iam()->attachRolePolicy([
                'RoleName' => $roleName,
                'PolicyArn' => $policyArn,
            ]);
        }
    }
}
