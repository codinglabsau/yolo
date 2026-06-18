<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\ServiceDiscovery\PrivateDnsNamespace;

/**
 * The environment's private Cloud Map DNS namespace. LongRunning because
 * namespace creation/deletion is an async Cloud Map operation the resource
 * blocks on — the next step resolves the namespace id, so create() can't
 * return before the operation lands. Teardown cascades the discovery
 * services first (see PrivateDnsNamespace::delete()).
 */
class SyncTypesenseNamespaceStep implements LongRunning, Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        return match (Lifecycle::state(Service::TYPESENSE)) {
            ServiceState::Provision => $this->syncResource(new PrivateDnsNamespace(), $options),
            ServiceState::Teardown => $this->teardownResource(new PrivateDnsNamespace(), $options),
        };
    }

    public function patienceMessage(): string
    {
        return 'Provisioning the private DNS namespace — usually under a minute.';
    }
}
