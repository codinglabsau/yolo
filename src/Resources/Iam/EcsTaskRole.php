<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\AwsLookups;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * YOLO-managed IAM role assumed by ECS tasks. Trust policy lets ecs-tasks.amazonaws.com
 * call sts:AssumeRole. Permission policies attached separately by
 * AttachEcsTaskRolePoliciesStep — convention is to attach the YOLO ECS task policy
 * (ssmmessages:* for ECS Exec) plus any per-app additions.
 */
class EcsTaskRole implements Resource
{
    public function name(): string
    {
        return Helpers::keyedResourceName(Iam::ECS_TASK_ROLE, exclusive: false);
    }

    public function tags(): array
    {
        return ['Name' => $this->name()];
    }

    public function exists(): bool
    {
        try {
            AwsLookups::ecsTaskRole();

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return AwsLookups::ecsTaskRole()['Arn'];
    }

    public function create(): void
    {
        Aws::iam()->createRole([
            'RoleName' => $this->name(),
            'Description' => 'YOLO managed ECS task role — shared default across all apps in this environment',
            'AssumeRolePolicyDocument' => json_encode($this->assumeRolePolicyDocument()),
            ...Aws::tags($this->tags()),
        ]);
    }

    public function synchroniseTags(): void
    {
        Aws::iam()->tagRole([
            'RoleName' => $this->name(),
            ...Aws::tags($this->tags()),
        ]);
    }

    /**
     * Trust-policy drift is reconciled by replacing the assume-role policy document.
     */
    public function synchroniseAssumeRolePolicy(): void
    {
        Aws::iam()->updateAssumeRolePolicy([
            'RoleName' => $this->name(),
            'PolicyDocument' => json_encode($this->assumeRolePolicyDocument()),
        ]);
    }

    public function assumeRolePolicyDocument(): array
    {
        return AwsLookups::ecsTaskAssumeRolePolicyDocument();
    }
}
