<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\Ssm\MeilisearchMasterKey;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Resources\CloudWatchLogs\MeilisearchLogGroup;

/**
 * Execution role for the shared Meilisearch task — write its logs and read the
 * master-key parameter for the task definition's `secrets`. Dedicated rather
 * than the shared EcsExecutionRole so the secret grant stays self-contained:
 * no app's execution role can ever read the master key, and the shared role's
 * policy set has a single writer. No ECR statement — the image is the public
 * upstream `getmeili/meilisearch`. The inline policy is written once at create
 * (frozen, like the rest of the Meilisearch stack); resource ARNs are built
 * from the manifest, not lookups, so create() has no ordering dependency.
 */
class MeilisearchExecutionRole implements Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName(Iam::MEILISEARCH_EXECUTION_ROLE);
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
            'Description' => 'YOLO managed Meilisearch execution role - writes search logs and reads the master key',
            'AssumeRolePolicyDocument' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [
                    [
                        'Effect' => 'Allow',
                        'Principal' => ['Service' => 'ecs-tasks.amazonaws.com'],
                        'Action' => 'sts:AssumeRole',
                    ],
                ],
            ]),
            ...Aws::tags($this->tags()),
        ]);

        Aws::iam()->putRolePolicy([
            'RoleName' => $this->name(),
            'PolicyName' => $this->name(),
            'PolicyDocument' => json_encode($this->policyDocument()),
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseIamRoleTags($this->name(), $this->tags(), $apply);
    }

    /**
     * @return array<string, mixed>
     */
    public function policyDocument(): array
    {
        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Effect' => 'Allow',
                    'Action' => ['logs:CreateLogStream', 'logs:PutLogEvents'],
                    'Resource' => sprintf(
                        'arn:aws:logs:%s:%s:log-group:%s:*',
                        Manifest::get('region'),
                        Aws::accountId(),
                        (new MeilisearchLogGroup())->name(),
                    ),
                ],
                [
                    'Effect' => 'Allow',
                    // GetParameters (plural) is the call the ECS agent makes to
                    // resolve task-definition `secrets` at launch.
                    'Action' => ['ssm:GetParameters'],
                    'Resource' => sprintf(
                        'arn:aws:ssm:%s:%s:parameter/%s',
                        Manifest::get('region'),
                        Aws::accountId(),
                        (new MeilisearchMasterKey())->name(),
                    ),
                ],
            ],
        ];
    }
}
