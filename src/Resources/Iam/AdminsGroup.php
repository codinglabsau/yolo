<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;

/**
 * Grant group for admin: members may assume the {@see AdminRole} and run
 * sync/scale across the environment, plus the account-tier sync the env admin
 * role carries (`yolo-{env}-admins`). The admin role also manages YOLO group
 * membership, so a member of this group can grant access to others — a
 * deliberate property for a small senior team.
 */
class AdminsGroup extends AssumeRoleGroup
{
    public function name(): string
    {
        return $this->keyedName(Iam::ADMINS_GROUP);
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    protected function role(): Resource
    {
        return new AdminRole();
    }

    /**
     * Admin subsumes every tier, and the grant must say so: commands mint the
     * LEAST-privileged role for their job (reads mint observer roles, deploys
     * the per-app deployer) regardless of who runs them, so without these ARNs
     * an admin would paradoxically be refused on any non-admin command. Only
     * the admin-role assume itself demands a fresh TOTP — the narrower tiers
     * ride the session's MFA, preserving least-privilege-by-default for admins
     * too. Wildcards are env-built (never the current app) so the document
     * stays deterministic across app checkouts.
     *
     * @return array<int, string>
     */
    #[\Override]
    protected function assumableRoleArns(): array
    {
        return [
            sprintf('arn:aws:iam::%s:role/%s', Aws::accountId(), $this->role()->name()),
            sprintf('arn:aws:iam::%s:role/%s', Aws::accountId(), (new ObserverRole())->name()),
            sprintf('arn:aws:iam::%s:role/yolo-%s-*-%s', Aws::accountId(), Helpers::environment(), Iam::OBSERVER_ROLE->value),
            sprintf('arn:aws:iam::%s:role/yolo-%s-*-%s', Aws::accountId(), Helpers::environment(), Iam::DEPLOYER_ROLE->value),
        ];
    }
}
