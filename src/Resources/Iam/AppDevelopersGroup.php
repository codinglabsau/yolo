<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;

/** Always provisioned (no repo gate), so a data-write grant can name a single app. */
class AppDevelopersGroup extends AssumeRoleGroup implements Deletable
{
    public function name(): string
    {
        return $this->keyedName(Iam::DEVELOPERS_GROUP);
    }

    public function scope(): Scope
    {
        return Scope::App;
    }

    protected function role(): Resource
    {
        return new AppDeveloperRole();
    }

    /**
     * Plus this app's observer role: read commands mint the per-app observer, so
     * without it an app developer would be refused on `status` for their own app.
     *
     * @return array<int, string>
     */
    #[\Override]
    protected function assumableRoleArns(): array
    {
        return [
            sprintf('arn:aws:iam::%s:role/%s', Aws::accountId(), $this->role()->name()),
            sprintf('arn:aws:iam::%s:role/%s', Aws::accountId(), (new AppObserverRole())->name()),
        ];
    }
}
