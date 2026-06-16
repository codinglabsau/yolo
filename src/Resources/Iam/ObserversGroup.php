<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;

/**
 * Grant group for env-wide read: members may assume the {@see ObserverRole} and
 * read every app in the environment (`yolo-{env}-observers`). Add a user to grant
 * environment-wide read; remove to revoke.
 */
class ObserversGroup extends AssumeRoleGroup
{
    public function name(): string
    {
        return $this->keyedName(Iam::OBSERVERS_GROUP);
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    protected function role(): Resource
    {
        return new ObserverRole();
    }
}
