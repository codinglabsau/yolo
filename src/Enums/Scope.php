<?php

namespace Codinglabs\Yolo\Enums;

/**
 * Ownership scope of a managed resource — the single source of truth that
 * replaces the binary AppScoped marker + keyedResourceName(exclusive:) bool.
 *
 * It drives three things at once, so they can't drift apart:
 *   - the resource's name (App → yolo-{env}-{app}-…; Env/Account → yolo-{env}-…)
 *   - its tags (yolo:app owner only on App; yolo:scope on all)
 *   - which sync tier is its single writer (sync:app / sync:platform / sync:account)
 */
enum Scope: string
{
    /** One app within one env — exclusive, yolo:app-tagged. Writer: sync:app. */
    case App = 'app';

    /** Shared within an env (VPC, subnets, ALB, shared roles). Writer: sync:platform. */
    case Env = 'env';

    /** Genuinely account-global (GitHub OIDC provider, MFA controls). Writer: sync:account. */
    case Account = 'account';

    /**
     * App resources get the app-exclusive name (yolo-{env}-{app}-…); env- and
     * account-scoped resources share the env/account name (yolo-{env}-…).
     */
    public function exclusive(): bool
    {
        return $this === self::App;
    }

    /** Env- and account-scoped resources are shared across apps. */
    public function shared(): bool
    {
        return $this !== self::App;
    }
}
