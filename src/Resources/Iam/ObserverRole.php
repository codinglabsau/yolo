<?php

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Aws\Iam as IamClient;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * YOLO-managed IAM role an operator or an agent (Claude / Leeloo) assumes for
 * **read-only** inspection of the environment — the `*-readonly` profile target
 * from LPX-635. It carries the env-shared {@see ObserverPolicy} policy (attached by
 * AttachObserverRolePolicyStep), so a profile assuming it can `describe`/`list`/
 * `get` exactly the services YOLO touches and **nothing mutating** — safe by
 * construction, not by convention.
 *
 * Env-scoped + shared: one `yolo-{env}-observer-role` per environment (the reads
 * are environment-agnostic; env scope just bounds the lifecycle and the policy's
 * object-read carve-out to this environment's config bucket).
 *
 * Trust: the account principal (`arn:aws:iam::{account}:root`) — i.e. any IAM
 * identity in the account that is itself granted `sts:AssumeRole` on this role may
 * assume it, so the real gate is that grant (the same model AWS recommends for
 * same-account role assumption). Wire your `YOLO_<ENV>_AWS_PROFILE` (or a dedicated
 * `*-readonly` profile) to assume this role via the existing 1Password
 * `credential_process` source. TODO(review): tighten the trust to the specific
 * operator/agent principal once that identity is settled.
 */
class ObserverRole implements Resource, SynchronisesConfiguration
{
    use ResolvesTags;
    use SynchronisesAssumeRolePolicy;

    public function name(): string
    {
        return $this->keyedName(Iam::OBSERVER_ROLE);
    }

    public function scope(): Scope
    {
        return Scope::Env;
    }

    public function exists(): bool
    {
        try {
            IamClient::role($this->name());

            return true;
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    public function arn(): string
    {
        return IamClient::role($this->name())['Arn'];
    }

    public function create(): void
    {
        Aws::iam()->createRole([
            'RoleName' => $this->name(),
            'Description' => $this->description(),
            'AssumeRolePolicyDocument' => json_encode($this->assumeRolePolicyDocument()),
            ...Aws::tags($this->tags()),
        ]);
    }

    /**
     * IAM Description fields enforce a restricted character set
     * (tab/LF/CR + printable ASCII + Latin-1 Supplement) — no em dashes,
     * smart quotes, or U+007F - U+00A0 control range. Validated by
     * IamDescriptionsAreSafeTest.
     */
    public function description(): string
    {
        return 'YOLO managed read-only role for safe operator/agent inspection of this environment';
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseIamRoleTags($this->name(), $this->tags(), $apply);
    }

    public function assumeRolePolicyDocument(): array
    {
        return [
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Effect' => 'Allow',
                    'Principal' => ['AWS' => sprintf('arn:aws:iam::%s:root', Aws::accountId())],
                    'Action' => 'sts:AssumeRole',
                ],
            ],
        ];
    }
}
