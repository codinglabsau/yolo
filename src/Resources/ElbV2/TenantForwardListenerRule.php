<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\ElbV2;

/**
 * One rule per tenant over the app's single target group (the ECS service
 * resolves the tenant from the host). The Name tag carries the tenant id so a
 * tenant's domain change stays scoped to its own rule, and removing a tenant
 * tears down its rule and nothing else.
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
