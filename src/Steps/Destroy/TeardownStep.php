<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

/**
 * Concrete steps carry the same gating contract as their sync counterpart
 * (ExecutesWebStep, ExecutesSoloStep, …) so a resource the app's shape never
 * created is never "torn down".
 */
abstract class TeardownStep implements Step
{
    use SynchronisesResource;

    public function __construct(protected string $environment = '') {}

    abstract protected function resource(): Resource&Deletable;

    public function __invoke(array $options): StepResult
    {
        return $this->teardownResource($this->resource(), $options);
    }
}
