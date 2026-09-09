<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Commands\PermissionsCommand;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

/**
 * Account-wide and tier-independent: carries only the self-service
 * credential-hygiene slice (enrol MFA, manage own keys/password) — no
 * `sts:AssumeRole` on anything, so membership has zero blast radius and is
 * safe to auto-manage for every IAM user in the account regardless of
 * whether an admin has granted a tier ({@see AssumeRoleGroup}) yet.
 * `sync:account` provisions the group and its policy; membership is a human
 * act like every other group here — {@see PermissionsCommand} enrols the user
 * it is editing on every run, whatever tiers are ticked, so self-service comes
 * with the user rather than with a tier. YOLO never enrols the account at
 * large: a user nobody has run `permissions` for is not YOLO's to touch.
 *
 * Shared across every environment (like {@see GithubOidcProvider}), so the
 * name is a literal, never env-keyed — running `sync:account` under any
 * environment argument must resolve to the same one group.
 */
class UsersGroup implements Deletable, Resource, SynchronisesConfiguration
{
    use GrantsSelfServiceMfa;
    use ResolvesTags;
    use SynchronisesGroupPolicy;

    public function name(): string
    {
        return sprintf('yolo-%s', Iam::USERS_GROUP->value);
    }

    public function scope(): Scope
    {
        return Scope::Account;
    }

    /**
     * @return array<string, mixed>
     */
    public function document(): array
    {
        return [
            'Version' => '2012-10-17',
            'Statement' => $this->selfServiceStatements(),
        ];
    }

    protected function policyName(): string
    {
        return sprintf('%s-self-service', $this->name());
    }
}
