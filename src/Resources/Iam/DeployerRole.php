<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * YOLO-managed IAM role a GitHub Actions workflow assumes (via OIDC) to deploy.
 * The trust policy federates to the account's GitHub OIDC provider and is scoped
 * to a single repository + branch, so only that workflow can assume it — no
 * stored AWS access keys. The deploy-time permission policy is provided by
 * DeployerPolicy and attached by AttachDeployerRolePoliciesStep.
 *
 * Shared per environment (yolo-{env}-deployer) like the task and execution
 * roles — the realistic YOLO topology is one deploying app per account+env.
 */
class DeployerRole implements Resource
{
    public function name(): string
    {
        return Helpers::keyedResourceName(Iam::DEPLOYER_ROLE, exclusive: false);
    }

    public function tags(): array
    {
        return ['Name' => $this->name()];
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
        return 'YOLO managed GitHub Actions OIDC deployer role for this environment';
    }

    public function synchroniseTags(): void
    {
        Aws::iam()->tagRole([
            'RoleName' => $this->name(),
            ...Aws::tags($this->tags()),
        ]);
    }

    /**
     * Trust-policy drift (e.g. the manifest repository/branch changed) is
     * reconciled by replacing the assume-role policy document.
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
        $repository = Manifest::get('deployer.repository');
        $branch = Manifest::get('deployer.branch', 'main');

        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Effect' => 'Allow',
                    'Principal' => ['Federated' => (new GithubOidcProvider())->arn()],
                    'Action' => 'sts:AssumeRoleWithWebIdentity',
                    'Condition' => [
                        'StringEquals' => [
                            sprintf('%s:aud', GithubOidcProvider::URL) => GithubOidcProvider::AUDIENCE,
                        ],
                        'StringLike' => [
                            sprintf('%s:sub', GithubOidcProvider::URL) => sprintf('repo:%s:ref:refs/heads/%s', $repository, $branch),
                        ],
                    ],
                ],
            ],
        ];
    }
}
