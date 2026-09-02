<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;

/**
 * Members may assume {@see AdminRole}. The admin role also manages YOLO group
 * membership, so a member can grant access to others — deliberate for a small team.
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
     * Commands mint the LEAST-privileged role for their job regardless of who runs
     * them, so without these ARNs an admin would be refused on any non-admin command.
     * Only the admin assume demands a fresh TOTP. Env-built, never the current app.
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
