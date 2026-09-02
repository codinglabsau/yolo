<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Concerns\SynchronisesResource;
use Codinglabs\Yolo\Resources\ServiceDiscovery\TypesenseDiscoveryService;

/**
 * On a greenfield plan the namespace doesn't exist yet, so each node reads as
 * absent and reports its pending create without resolving the sibling.
 *
 * Teardown is a skip: AWS refuses to delete a namespace with services in it,
 * so the namespace's delete cascades these. A node-count reduction's surplus
 * entries are removed by the nodes step, where the ordering (task stopped,
 * instance deregistered, then the DNS entry) lives in one place.
 */
class SyncTypesenseDiscoveryServicesStep implements Step
{
    use SynchronisesResource;

    public function __invoke(array $options): StepResult
    {
        if (Lifecycle::state(Service::TYPESENSE) !== ServiceState::Provision) {
            return StepResult::SKIPPED;
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');
        $pendingCreate = false;
        $pendingSync = false;

        foreach (range(0, Typesense::nodes() - 1) as $node) {
            $service = new TypesenseDiscoveryService($node);

            if (! $service->exists()) {
                $this->recordChange(Change::make($service->name(), 'absent', 'created'));
                $pendingCreate = true;

                if (! $dryRun) {
                    $service->create();
                }

                continue;
            }

            foreach ($this->synchroniseOwnedTags($service, $dryRun) as $key => $value) {
                $this->recordChange(Change::make(sprintf('%s tag %s', $service->name(), $key), null, $value));
                $pendingSync = true;
            }
        }

        return match (true) {
            $pendingCreate => $dryRun ? StepResult::WOULD_CREATE : StepResult::CREATED,
            $pendingSync => $dryRun ? StepResult::WOULD_SYNC : StepResult::SYNCED,
            default => StepResult::SYNCED,
        };
    }
}
