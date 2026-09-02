<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;

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

    /**
     * App-scoped commands (status, db:tunnel) mint the per-app observer role, so
     * without the wildcard an env observer would be refused on any single-app read.
     * Env-built, never the current app, so the document stays deterministic.
     *
     * @return array<int, string>
     */
    #[\Override]
    protected function assumableRoleArns(): array
    {
        return [
            sprintf('arn:aws:iam::%s:role/%s', Aws::accountId(), $this->role()->name()),
            sprintf('arn:aws:iam::%s:role/yolo-%s-*-%s', Aws::accountId(), Helpers::environment(), Iam::OBSERVER_ROLE->value),
        ];
    }
}
