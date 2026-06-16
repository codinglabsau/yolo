<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\Iam;

use Codinglabs\Yolo\Enums\Scope;

/**
 * Per-app variant of {@see ObserverRole}: the role an operator or agent assumes
 * to read ONE app. It carries {@see AppObserverPolicy} (attached by
 * AttachAppObserverRolePolicyStep), so the assumed session reads the app's
 * surface with log content fenced to the app's own group.
 *
 * App-scoped — one `yolo-{env}-{app}-observer-role` per app (the name follows
 * from the App scope through the shared OBSERVER_ROLE token) — so a grant
 * (membership of the app's observers group) can name a single app. Trust is the
 * same same-account-root model as the env observer role; the identity-side
 * `sts:AssumeRole` grant is the gate. Unlike the deployer role it has no OIDC
 * trust — reads are for humans/agents, never CI.
 */
class AppObserverRole extends ObserverRole
{
    #[\Override]
    public function scope(): Scope
    {
        return Scope::App;
    }

    #[\Override]
    public function description(): string
    {
        return 'YOLO managed read-only role for operator/agent inspection of this app';
    }
}
