<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Contracts;

interface HasSubSteps extends Step
{
    /**
     * The names of the sub-steps this step expands into.
     *
     * @return array<int, string>
     */
    public function subSteps(): array;
}
