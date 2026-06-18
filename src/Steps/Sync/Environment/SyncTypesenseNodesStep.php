<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Aws\ElbV2;
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
use Codinglabs\Yolo\Resources\ElbV2\SearchTargetGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Resources\ServiceDiscovery\TypesenseDiscoveryService;

/**
 * The Typesense node services (services.typesense.nodes of them — 3 or 5),
 * reconciled with the consensus protocol in mind:
 *
 *  - Existing nodes running an old task-definition revision roll FIRST,
 *    strictly one at a time, each waiting for ECS stability before the next —
 *    so a peer-list change (a node-count edit) lands on the survivors before
 *    any newcomer tries to join, and two nodes are never down at once.
 *  - Missing nodes are then created together (no inter-node waits) with one
 *    wait at the end — on first bootstrap a majority of peers must be up
 *    before the cluster can form at all, and on a grow the newcomers catch
 *    up from the already-rolled majority.
 *  - Shrinking (5 → 3) deletes the surplus node services last, after the
 *    survivors' rolled peer list has already dropped them — each ECS service
 *    is drained and removed, then its Cloud Map DNS entry (which has to wait
 *    out the instance deregistration).
 *
 * Full teardown is a deliberate skip: the services cluster's delete cascades
 * its services (AWS refuses to delete a cluster with active services), so the
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

        [$missing, $stale, $surplus] = $this->partition();

        if ($missing === [] && $stale === [] && $surplus === []) {
            return StepResult::SYNCED;
        }

        foreach ($missing as $service) {
            $this->recordChange(Change::make($service->name(), 'absent', 'created'));
        }

        foreach ($stale as $service) {
            $this->recordChange(Change::make($service->name(), 'previous revision', 'latest revision (rolled one node at a time)'));
        }

        foreach ($surplus as $service) {
            $this->recordChange(Change::make($service->name(), 'running', null));
        }

        if ($dryRun) {
            return match (true) {
                $missing !== [] => StepResult::WOULD_CREATE,
                $surplus !== [] => StepResult::WOULD_DELETE,
                default => StepResult::WOULD_SYNC,
            };
        }

        // A node is a load-balanced service, so ECS CreateService rejects it
        // until the search target group is associated with the ALB (a listener
        // rule forwards to it). On a green-field env the rule may not be in place
        // yet — the :443 listener is still being bootstrapped — so defer the
        // missing nodes to the next sync rather than crash. The changes are
        // already recorded above, so the plan still shows the pending nodes.
        if ($missing !== [] && ! $this->searchTargetGroupAttached()) {
            return StepResult::SKIPPED;
        }

        // Survivors first: the rolled image carries the new peer list, so the
        // standing quorum knows about joiners (and forgets leavers) before
        // either happens.
        foreach ($stale as $service) {
            $service->adoptLatestRevision();

            $this->waitForStability([$service->name()]);
        }

        // Then grow: create every missing node up front, one wait at the end.
        foreach ($missing as $service) {
            $service->create();
        }

        if ($missing !== []) {
            $this->waitForStability(array_map(fn (TypesenseService $service): string => $service->name(), $missing));
        }

        // Then shrink: the surplus nodes are no longer in anyone's peer list.
        foreach ($surplus as $service) {
            $this->removeNode($service);
        }

        return match (true) {
            $missing !== [] => StepResult::CREATED,
            $surplus !== [] => StepResult::DELETED,
            default => StepResult::SYNCED,
        };
    }

    public function patienceMessage(): string
    {
        return 'Rolling the Typesense nodes one at a time — a few minutes per node.';
    }

    /**
     * Split the declared node set into missing (never created) and stale
     * (running a task-definition revision older than the family's latest),
     * plus any surplus services left behind by a node-count reduction. On a
     * greenfield plan the family may not exist yet — every node then reads as
     * missing, which is exactly the pending state to report.
     *
     * @return array{0: array<int, TypesenseService>, 1: array<int, TypesenseService>, 2: array<int, TypesenseService>}
     */
    protected function partition(): array
    {
        $missing = [];
        $stale = [];
        $surplus = [];

        $latest = $this->latestRevisionArn();

        foreach (range(0, Typesense::nodes() - 1) as $node) {
            $service = new TypesenseService($node);

            if (! $service->exists()) {
                $missing[] = $service;

                continue;
            }

            if ($latest !== null && $service->current()['taskDefinition'] !== $latest) {
                $stale[] = $service;
            }
        }

        // Indexes above the declared count — only ever 3 and 4, since the
        // valid counts are 3 and 5. (range() counts DOWN when from > to, so
        // at the maximum count it must not run at all.)
        if (Typesense::nodes() < max(Typesense::NODE_COUNTS)) {
            foreach (range(Typesense::nodes(), max(Typesense::NODE_COUNTS) - 1) as $node) {
                $service = new TypesenseService($node);

                if ($service->exists()) {
                    $surplus[] = $service;
                }
            }
        }

        return [$missing, $stale, $surplus];
    }

    /**
     * Remove one surplus node: drain and delete its ECS service, then its
     * Cloud Map DNS entry — which AWS refuses to delete while the instance
     * deregistration is still settling, so that delete waits it out.
     */
    protected function removeNode(TypesenseService $service): void
    {
        Aws::ecs()->updateService([
            'cluster' => (new ServicesCluster())->name(),
            'service' => $service->name(),
            'desiredCount' => 0,
        ]);

        Aws::ecs()->deleteService([
            'cluster' => (new ServicesCluster())->name(),
            'service' => $service->name(),
            'force' => true,
        ]);

        (new TypesenseDiscoveryService($service->node()))->delete();
    }

    /**
     * Whether the search target group is associated with a load balancer yet —
     * i.e. a listener rule already forwards to it. ECS refuses to create a
     * load-balanced service against an unassociated target group, so the missing
     * nodes wait on the rule. An absent target group reads as not-yet-attached.
     */
    protected function searchTargetGroupAttached(): bool
    {
        try {
            return (ElbV2::targetGroup((new SearchTargetGroup())->name())['LoadBalancerArns'] ?? []) !== [];
        } catch (ResourceDoesNotExistException) {
            return false;
        }
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
