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
 * GitHub Actions deployer role, trusted via OIDC for one repository + ref so only that
 * workflow can assume it — keyless. App + env specific: both its trust and its
 * permissions are app-specific, so unlike the execution role it can't be shared.
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

    /** IAM Description allows only printable ASCII + Latin-1 (no em dashes or smart quotes) — pinned by IamDescriptionsAreSafeTest. */
    public function description(): string
    {
        return 'YOLO managed GitHub Actions OIDC deployer role for this environment';
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseIamRoleTags($this->name(), $this->tags(), $apply);
    }

    /** IAM refuses to delete a role that still holds policy attachments. */
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
                    // Local `yolo deploy`: same-account assumption caps a developer to
                    // this role's deploy policy. MFA is required on every human tier;
                    // the OIDC statement stays keyless since federated CI has no MFA.
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
     * Which GitHub ref may assume the role. Branch and tag together is a security
     * boundary error, so it fails loudly; `tag: true` means any tag.
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

    /** Fails loudly: a trust policy with a missing repo would be a silent security hole. */
    protected function repository(): string
    {
        return Helpers::githubRepository()
            ?? throw new IntegrityCheckException('Could not determine the GitHub repository for the deployer trust. Set `repository` in yolo.yml, or run from a GitHub clone (or GitHub Actions).');
    }
}
