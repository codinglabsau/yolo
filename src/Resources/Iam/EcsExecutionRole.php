<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Aws\Iam\Exception\IamException;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
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
class EcsExecutionRole implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;
    use SynchronisesAssumeRolePolicy;

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

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseIamRoleTags($this->name(), $this->tags(), $apply);
    }

    /**
     * Teardown when the environment is torn down (no app shares this role any
     * longer): IAM refuses to delete a role that still holds policy attachments,
     * so the AWS-managed AmazonECSTaskExecutionRolePolicy attachment and any
     * inline policies detach/delete before deleteRole. A concurrent delete that
     * already removed the role is tolerated.
     */
    public function delete(): void
    {
        try {
            $attached = Aws::iam()->listAttachedRolePolicies([
                'RoleName' => $this->name(),
            ])['AttachedPolicies'] ?? [];

            foreach ($attached as $policy) {
                Aws::iam()->detachRolePolicy([
                    'RoleName' => $this->name(),
                    'PolicyArn' => $policy['PolicyArn'],
                ]);
            }

            $inline = Aws::iam()->listRolePolicies([
                'RoleName' => $this->name(),
            ])['PolicyNames'] ?? [];

            foreach ($inline as $policyName) {
                Aws::iam()->deleteRolePolicy([
                    'RoleName' => $this->name(),
                    'PolicyName' => $policyName,
                ]);
            }

            Aws::iam()->deleteRole([
                'RoleName' => $this->name(),
            ]);
        } catch (IamException $e) {
            if ($e->getAwsErrorCode() !== 'NoSuchEntity') {
                throw $e;
            }
        }
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
