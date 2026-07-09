<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Aws\Iam\Exception\IamException;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * YOLO-managed IAM role a GitHub Actions workflow assumes (via OIDC) to deploy.
 * The trust policy federates to the account's GitHub OIDC provider and is scoped
 * to a single repository + ref (the environment's branch or tag), so only that
 * workflow can assume it — keyless. The deploy-time permission policy is provided
 * by DeployerPolicy and attached by AttachDeployerRolePoliciesStep.
 *
 * App + environment specific (yolo-{env}-{app}-deployer): both its trust (one
 * repo + ref) and its permissions (the app's ECR repo, buckets, cluster, service)
 * are app-specific, so unlike the shared ECS execution role it can't be shared.
 */
class DeployerRole implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;
    use SynchronisesAssumeRolePolicy;

    public function name(): string
    {
        return $this->keyedName(Iam::DEPLOYER_ROLE);
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
     * smart quotes, or U+007F - U+00A0 control range. Validated by
     * IamDescriptionsAreSafeTest.
     */
    public function description(): string
    {
        return 'YOLO managed GitHub Actions OIDC deployer role for this environment';
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseIamRoleTags($this->name(), $this->tags(), $apply);
    }

    /**
     * Teardown when the app drops its deployer (no GitHub repository, or the app
     * is being torn down): IAM refuses to delete a role that still holds policy
     * attachments, so the managed-policy attachments (AttachDeployerRolePoliciesStep's
     * work) detach and any inline policies delete before deleteRole. A concurrent
     * delete that already removed the role is tolerated.
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
                    // CI deploys (the primary path): the GitHub OIDC provider,
                    // scoped to one repo + ref — keyless and unchanged.
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
                [
                    // Local deploys (the Deployer tier): same-account assumption,
                    // so a developer running `yolo deploy` mints exactly this role's
                    // deploy-time policy on top of their own identity — capped to
                    // deploy, never their broader profile. The real gate is the
                    // identity-based `sts:AssumeRole` grant on the assuming
                    // principal (the same account-root model as the observer role).
                    // MFA is required on every tier (see ObserverRole) — the human
                    // path only; the OIDC statement above stays keyless, since a
                    // federated CI run has no MFA context to present.
                    'Effect' => 'Allow',
                    'Principal' => ['AWS' => sprintf('arn:aws:iam::%s:root', Aws::accountId())],
                    'Action' => 'sts:AssumeRole',
                    'Condition' => [
                        'Bool' => ['aws:MultiFactorAuthPresent' => 'true'],
                    ],
                ],
            ],
        ];
    }

    /**
     * The `sub` claim the OIDC token must match — which GitHub ref may assume the
     * role. Derived from the environment's source ref (a security boundary, so
     * setting both branch and tag fails loudly); defaults to the main branch:
     *   - branch: develop (default main)  → ref:refs/heads/develop  (push to branch, e.g. staging)
     *   - tag: 'v*' (or true = *)         → ref:refs/tags/v*        (tag push, e.g. production)
     */
    protected function subjectClaim(): string
    {
        $repository = $this->repository();

        if (Manifest::has('branch') && Manifest::has('tag')) {
            throw new IntegrityCheckException('An environment deploys from a branch or a tag, not both — set only one.');
        }

        if (Manifest::has('tag')) {
            $tag = Manifest::get('tag');

            return sprintf('repo:%s:ref:refs/tags/%s', $repository, $tag === true ? '*' : $tag);
        }

        return sprintf('repo:%s:ref:refs/heads/%s', $repository, Manifest::get('branch', 'main'));
    }

    /**
     * The org/repo scoping the trust — inferred from the git origin (or an
     * explicit manifest `repository`) via Helpers::githubRepository(). Fails
     * loudly when it can't be resolved: a trust policy with a missing repo would
     * be a silent security hole. (The Sync*Step gates skip provisioning entirely
     * when no GitHub repo is resolvable, so in practice this only fires if called
     * directly without a GitHub context.)
     */
    protected function repository(): string
    {
        return Helpers::githubRepository()
            ?? throw new IntegrityCheckException('Could not determine the GitHub repository for the deployer trust. Set `repository` in yolo.yml, or run from a GitHub clone (or GitHub Actions).');
    }
}
