<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\ElbV2;

/**
 * A tenant's forward rule — routes that one tenant's domain to the app's target
 * group. One per tenant, sharing the app's single target group: every tenant is
 * served by the same ECS service, which resolves the tenant from the request host.
 *
 * The Name tag carries the tenant id (`yolo-{env}-{app}-{tenant}`) so each
 * tenant's rule has its own identity on the shared `:443` listener. That is what
 * keeps a tenant's domain change scoped to that tenant's rule, and what lets
 * removing a tenant from the manifest tear down its rule and nothing else.
 *
 * A tenant that wildcards its own domain adds `*.{domain}` here, exactly as the
 * app-level rule does — the flag means the same thing at either level. Its
 * apex/`www` sibling, when it has one, is 301'd by a
 * {@see TenantRedirectListenerRule}.
 */
class TenantForwardListenerRule extends ForwardListenerRule
{
    public function __construct(
        string $httpsListenerArn,
        protected string $tenantId,
        protected string $domain,
        protected ?string $wildcardHost = null,
    ) {
        parent::__construct($httpsListenerArn);
    }

    #[\Override]
    public function name(): string
    {
        return $this->keyedName($this->tenantId);
    }

    #[\Override]
    public function hosts(): array
    {
        return array_values(array_filter([$this->domain, $this->wildcardHost]));
    }
}
