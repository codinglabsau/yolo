<?php

namespace Codinglabs\Yolo\Resources\Iam;

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
}
