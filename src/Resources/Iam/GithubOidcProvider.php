<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The account-level IAM OIDC identity provider for GitHub Actions. There can be
 * exactly one provider per URL per account — it is shared across every YOLO
 * environment and app, so it is deliberately NOT keyed by environment. The
 * resource declares Scope::Account, which makes `Aws::expectedTags()` skip the
 * `yolo:environment` baseline (it would be a false label and a teardown hazard);
 * `ResolvesTags` still stamps `yolo:scope=account` as the positive ownership
 * marker so `audit:account` can recognise it.
 *
 * The deployer role's trust policy federates to this provider's ARN, letting a
 * GitHub Actions workflow exchange its OIDC token for short-lived AWS credentials
 * via sts:AssumeRoleWithWebIdentity — keyless.
 */
class GithubOidcProvider implements Resource
{
    use ResolvesTags;

    public const URL = 'token.actions.githubusercontent.com';

    public const AUDIENCE = 'sts.amazonaws.com';

    /**
     * GitHub's OIDC certificate thumbprints. AWS now validates the GitHub IdP
     * against its own trusted-CA library, so these are largely vestigial — but
     * CreateOpenIDConnectProvider still accepts the list, so we pin the known
     * values rather than rely on undocumented optional behaviour.
     */
    public const THUMBPRINTS = [
        '6938fd4d98bab03faadb97b34396831e3780aea1',
        '1c58a3a8518e8759bf075b76b750d4f2df264fcd',
    ];

    public function name(): string
    {
        return self::URL;
    }

    public function scope(): Scope
    {
        return Scope::Account;
    }

    public function exists(): bool
    {
        try {
            IamClient::openIdConnectProvider($this->arn());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        // IAM ARNs carry no region; the OIDC provider ARN is fully derivable from
        // the account id and the well-known GitHub URL — no lookup required.
        return sprintf('arn:aws:iam::%s:oidc-provider/%s', Aws::accountId(), self::URL);
    }

    public function create(): void
    {
        Aws::iam()->createOpenIDConnectProvider([
            'Url' => sprintf('https://%s', self::URL),
            'ClientIDList' => [self::AUDIENCE],
            'ThumbprintList' => self::THUMBPRINTS,
            ...Aws::tags($this->tags()),
        ]);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseIamOidcProviderTags($this->arn(), $this->tags(), $apply);
    }
}
