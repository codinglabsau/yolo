<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Services\Typesense;
use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Resources\Ecs\ServicesCluster;
use Codinglabs\Yolo\Resources\Ecs\TypesenseService;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;

/**
 * The three Typesense node services, reconciled with Raft in mind:
 *
 *  - First provision creates all three together (no inter-node waits) — the
 *    quorum can only form once a majority of peers is up, so waiting on node
 *    0 before starting node 1 would deadlock the bootstrap.
 *  - Updates (a new task-definition revision from a version bump or key
 *    rotation) roll strictly one node at a time, each waiting for the ECS
 *    service to stabilise before the next starts — the catch-up gate that
 *    keeps two nodes from being down at once, so the quorum holds throughout.
 *
 * Teardown is a deliberate skip: the services cluster's delete cascades its
 * services (AWS refuses to delete a cluster with active services), so the
 * cluster step earlier in the declared order owns it.
 */
class SyncTypesenseNodesStep implements LongRunning, Step
{
    use RecordsChanges;

    public function __invoke(array $options): StepResult
    {
        if (Lifecycle::state(Service::TYPESENSE) !== ServiceState::Provision) {
            return StepResult::SKIPPED;
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');

        [$missing, $stale] = $this->partition();

        if ($missing === [] && $stale === []) {
            return StepResult::SYNCED;
        }

        foreach ($missing as $service) {
            $this->recordChange(Change::make($service->name(), 'absent', 'created'));
        }

        foreach ($stale as $service) {
            $this->recordChange(Change::make($service->name(), 'previous revision', 'latest revision (rolled one node at a time)'));
        }

        if ($dryRun) {
            return $missing !== [] ? StepResult::WOULD_CREATE : StepResult::WOULD_SYNC;
        }

        // Bootstrap: create every missing node up front, then wait once — a
        // majority of peers must be up before Raft can form at all.
        foreach ($missing as $service) {
            $service->create();
        }

        if ($missing !== []) {
            $this->waitForStability(array_map(fn (TypesenseService $service): string => $service->name(), $missing));
        }

        // Rolling update: one node at a time, each gated on stability.
        foreach ($stale as $service) {
            $service->adoptLatestRevision();

            $this->waitForStability([$service->name()]);
        }

        return $missing !== [] ? StepResult::CREATED : StepResult::SYNCED;
    }

    public function patienceMessage(): string
    {
        return 'Rolling the Typesense nodes one at a time — a few minutes per node.';
    }

    /**
     * Split the three nodes into missing (never created) and stale (running a
     * task-definition revision older than the family's latest). On a
     * greenfield plan the family may not exist yet — every node then reads as
     * missing, which is exactly the pending state to report.
     *
     * @return array{0: array<int, TypesenseService>, 1: array<int, TypesenseService>}
     */
    protected function partition(): array
    {
        $missing = [];
        $stale = [];

        $latest = $this->latestRevisionArn();

        foreach (range(0, Typesense::NODES - 1) as $node) {
            $service = new TypesenseService($node);

            if (! $service->exists()) {
                $missing[] = $service;

                continue;
            }

            if ($latest !== null && $service->current()['taskDefinition'] !== $latest) {
                $stale[] = $service;
            }
        }

        return [$missing, $stale];
    }

    protected function latestRevisionArn(): ?string
    {
        try {
            return Ecs::taskDefinition((new TypesenseService(0))->taskDefinitionFamily())['taskDefinitionArn'] ?? null;
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }

    /**
     * @param  array<int, string>  $services
     */
    protected function waitForStability(array $services): void
    {
        Aws::waitFor(Aws::ecs(), 'ServicesStable', [
            'cluster' => (new ServicesCluster())->name(),
            'services' => $services,
        ], timeout: 600, interval: 15);
    }
}
