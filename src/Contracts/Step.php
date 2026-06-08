<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Contracts;

use Codinglabs\Yolo\Enums\StepResult;

interface Step
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __invoke(array $options): StepResult;
}
