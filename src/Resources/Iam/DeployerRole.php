<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
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
                            sprintf('%s:sub', GithubOidcProvider::URL) => $this->subjectClaim(),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The `sub` claim the OIDC token must match — which GitHub context may assume
     * the role. Exactly one trigger may be set (a security boundary, so ambiguity
     * fails loudly); defaults to the main branch:
     *   - branch: main (default)   → ref:refs/heads/main      (push to branch, e.g. staging)
     *   - tag: 'v*' (or true = *)  → ref:refs/tags/v*         (tag push, e.g. production)
     *   - environment: production  → environment:production   (GitHub environment rules)
     */
    protected function subjectClaim(): string
    {
        $repository = $this->repository();

        $triggers = array_keys(array_filter([
            'branch' => Manifest::has('deployer.branch'),
            'tag' => Manifest::has('deployer.tag'),
            'environment' => Manifest::has('deployer.environment'),
        ]));

        if (count($triggers) > 1) {
            throw new IntegrityCheckException(sprintf('Ambiguous deployer trust scope — set only one of branch, tag, or environment (got %s).', implode(', ', $triggers)));
        }

        if (Manifest::has('deployer.tag')) {
            $tag = Manifest::get('deployer.tag');

            return sprintf('repo:%s:ref:refs/tags/%s', $repository, $tag === true ? '*' : $tag);
        }

        if (Manifest::has('deployer.environment')) {
            return sprintf('repo:%s:environment:%s', $repository, Manifest::get('deployer.environment'));
        }

        return sprintf('repo:%s:ref:refs/heads/%s', $repository, Manifest::get('deployer.branch', 'main'));
    }

    /**
     * The org/repo scoping the trust. Explicit `deployer.repository` wins;
     * otherwise it's inferred from GITHUB_REPOSITORY or the git origin remote so
     * the manifest needs no repo config. Fails loudly when it can't be resolved —
     * a trust policy with a missing repo would be a silent security hole.
     */
    protected function repository(): string
    {
        return Manifest::get('deployer.repository')
            ?? Helpers::githubRepository()
            ?? throw new IntegrityCheckException('Could not determine the deployer repository. Set `deployer.repository` in yolo.yml, or run from a GitHub clone (or GitHub Actions).');
    }
}
