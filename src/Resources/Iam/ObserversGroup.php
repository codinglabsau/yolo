<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
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

    /**
     * Env-wide read subsumes per-app read, and the grant must say so: app-scoped
     * commands (status, db:tunnel) mint the narrower per-app observer role, so
     * without these ARNs an env observer would paradoxically be refused on any
     * single-app read. The wildcard covers every `yolo-{env}-{app}-observer-role`
     * (present and future — that's the point of env-wide) and cannot match the
     * plain env role or any non-observer role; it's built from env only, never
     * the current app, so the document stays deterministic across app checkouts.
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
