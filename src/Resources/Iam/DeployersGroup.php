<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;

/**
 * Grant group for per-app deploy: members may assume this app's
 * {@see DeployerRole} and deploy ONE app (`yolo-{env}-{app}-deployers`). The
 * effective per-app grant — deploy mutations DO scope to the app's resources, so
 * membership of this group genuinely confines a developer to deploying this app.
 * Provisioned only when the app has a deployer role (a GitHub repository).
 */
class DeployersGroup extends AssumeRoleGroup
{
    public function name(): string
    {
        return $this->keyedName(Iam::DEPLOYERS_GROUP);
    }

    public function scope(): Scope
    {
        return Scope::App;
    }

    protected function role(): Resource
    {
        return new DeployerRole();
    }
}
