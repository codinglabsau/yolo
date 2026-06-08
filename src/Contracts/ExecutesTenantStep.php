<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Contracts;

interface ExecutesTenantStep extends ExecutesMultitenancyStep
{
    public function tenantId(): string;

    public function config(): array;

    public function setTenantId(string $tenantId): self;

    public function setConfig(array $config): self;
}
