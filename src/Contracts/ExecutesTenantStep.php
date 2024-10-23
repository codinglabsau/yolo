<?php

namespace Codinglabs\Yolo\Contracts;

interface ExecutesTenantStep extends Step
{
    public function tenantId(): string;

    public function config(): array;

    public function setTenantId(string $tenantId): self;

    public function setConfig(array $config): self;
}
