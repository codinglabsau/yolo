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
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\ServiceDiscovery\TypesenseDiscoveryService;

/**
 * One Cloud Map DNS service per Typesense node (typesense-0/1/2). On a
 * greenfield plan the namespace doesn't exist yet, so each node simply reads
 * as absent and reports its pending create without resolving the sibling
 * (the two-pass contract); by apply the namespace step has run.
 *
 * Teardown is a deliberate skip: AWS refuses to delete a namespace with
 * services in it, so the namespace's delete cascades these — same pattern as
 * the IVS rule/target pair.
 */
class SyncTypesenseDiscoveryServicesStep implements Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        if (Lifecycle::state(Service::TYPESENSE) !== ServiceState::Provision) {
            return StepResult::SKIPPED;
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');
        $pendingCreate = false;
        $pendingSync = false;

        foreach (range(0, Typesense::NODES - 1) as $node) {
            $service = new TypesenseDiscoveryService($node);

            if (! $service->exists()) {
                $this->recordChange(Change::make($service->name(), 'absent', 'created'));
                $pendingCreate = true;

                if (! $dryRun) {
                    $service->create();
                }

                continue;
            }

            foreach ($service->synchroniseTags(apply: ! $dryRun) as $key => $value) {
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
