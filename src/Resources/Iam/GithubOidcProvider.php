<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Enums\Scope;
use Aws\Iam\Exception\IamException;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Adoptable;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * One OIDC provider per URL per account, shared by every environment — so it is
 * Scope::Account, not env-keyed: `Aws::expectedTags()` skips `yolo:environment`
 * (a false label and a teardown hazard) while `yolo:scope=account` still marks it
 * for `audit:account`.
 */
class GithubOidcProvider implements Adoptable, Deletable, Resource
{
    use ResolvesTags;

    public const URL = 'token.actions.githubusercontent.com';

    public const AUDIENCE = 'sts.amazonaws.com';

    /**
     * Largely vestigial (AWS now validates GitHub against its own trusted CAs) but
     * CreateOpenIDConnectProvider still accepts the list, so pin the known values.
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
        // IAM ARNs carry no region, so this is derivable without a lookup.
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

    /**
     * Account-scoped and shared, so the destroy orchestrator gates this on no other
     * environment remaining.
     */
    public function delete(): void
    {
        try {
            Aws::iam()->deleteOpenIDConnectProvider([
                'OpenIDConnectProviderArn' => $this->arn(),
            ]);
        } catch (IamException $e) {
            if ($e->getAwsErrorCode() !== 'NoSuchEntity') {
                throw $e;
            }
        }
    }
}
