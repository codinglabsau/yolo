<?php

namespace Codinglabs\Yolo\Contracts;

interface HasSubSteps extends Step
{
    public function __invoke(): array;
}
