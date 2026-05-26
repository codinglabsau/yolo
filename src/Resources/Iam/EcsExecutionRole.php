<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * YOLO-managed IAM role the ECS agent assumes at task launch to pull the image
 * from ECR and write container logs to CloudWatch. Distinct from the task role
 * (which the running container's app code uses). Trust policy lets
 * ecs-tasks.amazonaws.com call sts:AssumeRole; the AWS-managed
 * AmazonECSTaskExecutionRolePolicy is attached by AttachEcsExecutionRolePoliciesStep.
 *
 * Replaces the previous reliance on the AWS-convention `ecsTaskExecutionRole`,
 * which AWS does not auto-create — green-field accounts never had it.
 */
class EcsExecutionRole implements Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName(Iam::ECS_EXECUTION_ROLE);
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        try {
            IamClient::role($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return IamClient::role($this->name())['Arn'];
    }

    public function create(): void
    {
        Aws::iam()->createRole([
            'RoleName' => $this->name(),
            'Description' => $this->description(),
            'AssumeRolePolicyDocument' => json_encode($this->assumeRolePolicyDocument()),
            ...Aws::tags($this->tags()),
        ]);
    }

    /**
     * IAM Description fields enforce a restricted character set
     * (tab/LF/CR + printable ASCII + Latin-1 Supplement) — no em dashes,
     * smart quotes, or U+007F - U+00A0 control range. Validated by
     * IamDescriptionsAreSafeTest.
     */
    public function description(): string
    {
        return 'YOLO managed ECS execution role - pulls images and writes logs for all apps in this environment';
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
        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Effect' => 'Allow',
                    'Principal' => ['Service' => 'ecs-tasks.amazonaws.com'],
                    'Action' => 'sts:AssumeRole',
                ],
            ],
        ];
    }
}
