<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\ElbV2;

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
