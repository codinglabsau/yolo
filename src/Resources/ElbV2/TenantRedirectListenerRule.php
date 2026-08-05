<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\ElbV2;

/**
 * A tenant's apex/`www` redirect — 301s the sibling half of that tenant's
 * canonical host to it, the per-tenant twin of {@see RedirectListenerRule}.
 *
 * Both halves resolve to the ALB (the deploy-time record sync writes the pair),
 * and the tenant's certificate covers the apex plus its `*.{apex}` wildcard, so
 * the sibling is TLS-valid before the redirect fires.
 *
 * Only meaningful when the tenant's domain is the apex or `www.{apex}`; the step
 * driving this rule gates on that.
 */
class TenantRedirectListenerRule extends RedirectListenerRule
{
    public function __construct(
        string $httpsListenerArn,
        protected string $tenantId,
        protected string $domain,
        protected string $apex,
    ) {
        parent::__construct($httpsListenerArn);
    }

    #[\Override]
    public function name(): string
    {
        return $this->keyedName("{$this->tenantId}-redirect");
    }

    #[\Override]
    public function hosts(): array
    {
        return [$this->wwwSibling($this->apex, $this->domain)];
    }

    #[\Override]
    protected function canonicalHost(): string
    {
        return $this->domain;
    }
}
