<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\Ecr\TypesenseRepository;

/**
 * The env-scoped repository holding the environment's Typesense image.
 * Teardown force-deletes it, images included — the image is rebuildable from
 * the manifest's version + the env .env's key whenever the offer returns.
 */
class SyncTypesenseEcrRepositoryStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return match (Lifecycle::state(Service::TYPESENSE)) {
            ServiceState::Provision => $this->syncResource(new TypesenseRepository(), $options),
            ServiceState::Teardown => $this->teardownResource(new TypesenseRepository(), $options),
        };
    }
}
