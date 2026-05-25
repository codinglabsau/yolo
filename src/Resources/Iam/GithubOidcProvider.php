<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The account-level IAM OIDC identity provider for GitHub Actions. There can be
 * exactly one provider per URL per account — it is shared across every YOLO
 * environment and app, so it is deliberately NOT keyed by environment and never
 * carries the yolo:environment tag.
 *
 * The deployer role's trust policy federates to this provider's ARN, letting a
 * GitHub Actions workflow exchange its OIDC token for short-lived AWS credentials
 * via sts:AssumeRoleWithWebIdentity (no stored access keys).
 */
class GithubOidcProvider implements Resource
{
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

    public function tags(): array
    {
        return ['Name' => self::URL];
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
            // Shared account singleton — tag with Name only, never yolo:environment.
            'Tags' => [
                ['Key' => 'Name', 'Value' => self::URL],
            ],
        ]);
    }

    public function synchroniseTags(): void
    {
        Aws::iam()->tagOpenIDConnectProvider([
            'OpenIDConnectProviderArn' => $this->arn(),
            'Tags' => [
                ['Key' => 'Name', 'Value' => self::URL],
            ],
        ]);
    }
}
