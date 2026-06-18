<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Steps\Destroy;

use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Concerns\SynchronisesResource;

/**
 * Base for app-teardown steps. Each names a single Resource&Deletable; the step
 * tears it down via {@see SynchronisesResource::teardownResource()} — exists ⇒
 * record the change and delete (WOULD_DELETE on the plan pass); absent ⇒ SKIPPED.
 * The recorded "provisioned → absent" Change is what surfaces the resource in the
 * destroy plan and keeps it in the apply pass (the pending-only prune).
 *
 * Concrete steps add the same gating contract their sync counterpart carries
 * (ExecutesWebStep, ExecutesSoloStep, …) so destroy:app honours the identical
 * app-shape gates — the resources a config never created are never "torn down".
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
