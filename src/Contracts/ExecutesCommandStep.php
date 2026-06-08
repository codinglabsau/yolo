<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Contracts;

interface ExecutesCommandStep extends Step
{
    public function __construct(string $environment, string $command);

    public function name(): string;
}
