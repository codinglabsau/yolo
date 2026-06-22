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
 * YOLO-managed IAM role an operator assumes to **provision** an environment — the
 * Admin tier. `yolo sync` / `yolo scale` run capped to this
 * role so a local operator can never exceed YOLO's own blast radius, even when
 * their personal identity is account-admin. It carries the read surface
 * ({@see ObserverPolicy}) plus the write surface ({@see AdminPolicy}).
 *
 * Env-scoped + shared: one `yolo-{env}-admin-role` per environment, since the
 * resources sync writes are environment-bounded.
 *
 * Bootstrap is self-activating, not a chicken-and-egg: the FIRST `yolo sync` of an
 * environment runs on the operator's profile (the role doesn't exist yet) and
 * creates this role; every sync after that mints it. So the tier turns itself on
 * the moment it's provisioned — no `--privileged` bootstrap flag needed.
 *
 * Trust: the account principal (`arn:aws:iam::{account}:root`), so any account
 * identity itself granted `sts:AssumeRole` on this role may assume it — the same
 * same-account model as {@see ObserverRole}. TODO(review): tighten to the specific
 * operator principal once that identity is settled, and decide whether the write
 * surface needs a permissions boundary (see AdminPolicy).
 */
class AdminRole implements Resource, SynchronisesConfiguration
{
    use ResolvesTags;
    use SynchronisesAssumeRolePolicy;

    public function name(): string
    {
        return $this->keyedName(Iam::ADMIN_ROLE);
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
        return 'YOLO managed admin role that caps yolo sync and scale to YOLO-owned resources';
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
                    'Principal' => ['AWS' => sprintf('arn:aws:iam::%s:root', Aws::accountId())],
                    'Action' => 'sts:AssumeRole',
                    // The admin tier is the only one that requires MFA to assume —
                    // so escalating to it is an explicit human act, never implicit.
                    // YOLO passes a fresh TOTP at mint time (mintTierCredentials),
                    // which an agent running as the operator can't generate. It's
                    // AWS-enforced, not a CLI prompt: a direct AssumeRole without
                    // MFA is denied here, so the gate can't be bypassed by going
                    // around YOLO. Observer/deployer carry no such condition.
                    'Condition' => [
                        'Bool' => ['aws:MultiFactorAuthPresent' => 'true'],
                    ],
                ],
            ],
        ];
    }
}
