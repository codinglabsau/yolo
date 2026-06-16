<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;

/**
 * Grant group for per-app read: members may assume this app's
 * {@see AppObserverRole} and read ONE app, with log content fenced to its group
 * (`yolo-{env}-{app}-observers`). Add a user to grant read on this app only.
 */
class AppObserversGroup extends AssumeRoleGroup
{
    public function name(): string
    {
        return $this->keyedName(Iam::OBSERVERS_GROUP);
    }

    public function scope(): Scope
    {
        return Scope::App;
    }

    protected function role(): Resource
    {
        return new AppObserverRole();
    }
}
