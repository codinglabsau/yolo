<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * YOLO-managed IAM role assumed by this app's ECS tasks (web, queue and
 * scheduler all share the one app role). App-scoped so each app gets its own
 * role — additional permissions an app grants (via `task-role-policies`) can't
 * bleed into any other app. Trust policy lets ecs-tasks.amazonaws.com call
 * sts:AssumeRole. Permission policies are attached separately by
 * AttachEcsTaskRolePoliciesStep: the YOLO baseline task policy (ECS Exec + this
 * app's SQS/SES) plus any manifest-declared additions.
 */
class EcsTaskRole implements Resource, SynchronisesConfiguration
{
    use ResolvesTags;
    use SynchronisesAssumeRolePolicy;

    public function name(): string
    {
        return $this->keyedName(Iam::ECS_TASK_ROLE);
    }

    public function scope(): Scope
    {
        return Scope::App;
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
     * smart quotes, or U+007F – U+00A0 control range. Validated by
     * IamDescriptionsAreSafeTest.
     */
    public function description(): string
    {
        return 'YOLO managed ECS task role - the runtime identity for this app\'s containers';
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseIamRoleTags($this->name(), $this->tags(), $apply);
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
