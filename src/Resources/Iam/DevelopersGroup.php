<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;

class DevelopersGroup extends AssumeRoleGroup
{
    public function name(): string
    {
        return $this->keyedName(Iam::DEVELOPERS_GROUP);
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    protected function role(): Resource
    {
        return new DeveloperRole();
    }

    /**
     * The admins list minus the deployer wildcard: commands mint the least-privileged
     * role for their job, so a developer must also be able to assume the observer
     * roles or every read command would refuse them. Env-built, never the current app.
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
            sprintf('arn:aws:iam::%s:role/yolo-%s-*-%s', Aws::accountId(), Helpers::environment(), Iam::DEVELOPER_ROLE->value),
        ];
    }
}
