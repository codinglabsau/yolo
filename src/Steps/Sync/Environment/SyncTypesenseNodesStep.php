<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use RuntimeException;
use GuzzleHttp\Client;
use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\WaitReporter;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Services\Lifecycle;
use Codinglabs\Yolo\Services\Typesense;
use GuzzleHttp\Exception\GuzzleException;
use Codinglabs\Yolo\Contracts\LongRunning;
use Codinglabs\Yolo\Concerns\RecordsChanges;
use Codinglabs\Yolo\Concerns\RecordsWarnings;
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
 *    strictly one at a time — so a peer-list change (a node-count edit) lands
 *    on the survivors before any newcomer tries to join, and two nodes are
 *    never down at once. Each roll waits for CLUSTER truth, not just ECS
 *    truth: ECS "services stable" only proves a task launched, not that the
 *    node passed its boot gate, joined the raft and caught up — advancing on
 *    it can take a second node down while the first is still absent, and on
 *    an ephemeral-disk cluster a shrinking quorum is how a rolling update
 *    cascades into total data loss. So after each node the step polls the
 *    replacement's own target health and the cluster's /health + leader
 *    state, bounded, and ABORTS the roll (remaining nodes untouched) rather
 *    than proceed past a node that never came back.
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
    use RecordsWarnings;

    /**
     * Bounded wait per rolled node for the replacement to serve AND the
     * cluster to converge — generous because a replacement replays the whole
     * dataset from the surviving majority before /health goes green.
     */
    protected const int ROLL_GATE_ATTEMPTS = 60;

    protected const int ROLL_GATE_INTERVAL_SECONDS = 10;

    /**
     * Consecutive clean /health samples that count as "every node caught up".
     * The probe rides the public search host, and the ALB spreads one-request
     * connections across ALL live targets — the liveness health check keeps a
     * catching-up node in rotation, answering 503 — so a run of clean samples
     * this long means no routed node is still lagging.
     */
    protected const int CONVERGED_SAMPLES = 12;

    public function __construct(protected string $environment = '', protected ?Client $http = null) {}

    /** Whether any roll-gate sample got an HTTP answer at all — an abort with
     * none points at operator-side reachability, not the cluster. */
    protected bool $clusterAnswered = false;

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
        // either happens. Each node must prove it rejoined — serving in the
        // target group, cluster /health clean, leader present — before the
        // next one is touched.
        foreach (array_values($stale) as $index => $service) {
            $service->adoptLatestRevision();

            $this->waitForStability([$service->name()]);

            $this->assertNodeRejoined($service, rolled: $index + 1, total: count($stale));
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
        return 'Rolling the Typesense nodes one at a time, each proving it rejoined the cluster — a few minutes per node.';
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
     * The roll gate: block until the replaced node proves it rejoined the
     * cluster, or abort the whole roll. Two stages, each bounded:
     *
     *  1. The replacement task's own target answers in the search target
     *     group — the ECS waiter can pass while the new container still sits
     *     in its entrypoint boot gate, never serving, so this is the "it
     *     actually came up" check ECS stability isn't.
     *  2. The cluster reports converged through the public search host — a
     *     run of consecutive clean /health samples across the rotation (a
     *     catching-up node answers 503, so one lagging node breaks the run)
     *     and a leader present per /debug.
     *
     * On timeout it throws with the roll position — remaining nodes are left
     * untouched, because rolling past a node that never rejoined is exactly
     * the move that turns one lost node into a lost cluster.
     */
    protected function assertNodeRejoined(TypesenseService $service, int $rolled, int $total): void
    {
        if (! $this->awaitReplacementServing($service)) {
            $this->abortRoll($service, $rolled, $total, 'its replacement task never turned healthy in the search target group (likely still waiting on peer DNS in its boot gate)');
        }

        $searchHost = Typesense::searchHost();

        if ($searchHost === null) {
            // Unreachable in practice — the target-group step hard-requires the
            // search host before any node exists — but a missing host must not
            // fail a roll the operator can't do anything about mid-flight.
            $this->recordWarning(sprintf('Rolled %s without cluster-truth verification — the environment manifest declares no domain, so there is no public search host to probe.', $service->name()));

            return;
        }

        if (! $this->awaitClusterConverged($searchHost)) {
            $this->abortRoll($service, $rolled, $total, $this->clusterAnswered
                ? sprintf('the cluster answered through https://%s but never reported converged (clean /health across the rotation with a leader present)', $searchHost)
                : sprintf('no HTTP response was ever received from https://%s — check reachability from this machine (DNS, proxy, WAF) as much as the cluster itself', $searchHost));
        }
    }

    /**
     * @return never
     */
    protected function abortRoll(TypesenseService $service, int $rolled, int $total, string $reason): void
    {
        throw new RuntimeException(sprintf(
            'Aborting the Typesense node roll at %s (%d of %d): %s within the bounded wait. The remaining nodes were left on their current revision — investigate the node (status:logs, the CloudWatch dashboard), then run the sync again to resume the roll.',
            $service->name(),
            $rolled,
            $total,
            $reason,
        ));
    }

    /**
     * Stage 1, bounded: the rolled service's RUNNING task registers a healthy
     * target in the search target group. Health there is process liveness, so
     * "healthy" means the container made it through its boot gate and the API
     * answers — not that it caught up; that's stage 2.
     */
    protected function awaitReplacementServing(TypesenseService $service): bool
    {
        for ($attempt = 1; $attempt <= self::ROLL_GATE_ATTEMPTS; $attempt++) {
            if ($this->replacementServing($service)) {
                return true;
            }

            if ($attempt < self::ROLL_GATE_ATTEMPTS) {
                $this->pause(self::ROLL_GATE_INTERVAL_SECONDS);
            }
        }

        return false;
    }

    protected function replacementServing(TypesenseService $service): bool
    {
        $cluster = (new ServicesCluster())->name();

        $taskArns = Ecs::runningTasks($cluster, $service->name());

        if ($taskArns === []) {
            return false;
        }

        $tasks = Aws::ecs()->describeTasks(['cluster' => $cluster, 'tasks' => $taskArns])['tasks'];

        $targetHealth = Aws::elasticLoadBalancingV2()->describeTargetHealth([
            'TargetGroupArn' => (new SearchTargetGroup())->arn(),
        ])['TargetHealthDescriptions'];

        return static::tasksAreServing($tasks, $targetHealth);
    }

    /**
     * Pure check: every given task's private IP answers as a healthy target.
     *
     * @param  array<int, array<string, mixed>>  $tasks
     * @param  array<int, array<string, mixed>>  $targetHealth
     */
    public static function tasksAreServing(array $tasks, array $targetHealth): bool
    {
        $taskIps = collect($tasks)
            ->map(fn (array $task) => data_get(
                collect($task['attachments'] ?? [])
                    ->flatMap(fn (array $attachment) => $attachment['details'] ?? [])
                    ->firstWhere('name', 'privateIPv4Address'),
                'value',
            ))
            ->filter()
            ->values();

        if ($taskIps->isEmpty()) {
            return false;
        }

        $healthyIps = collect($targetHealth)
            ->filter(fn (array $target): bool => data_get($target, 'TargetHealth.State') === 'healthy')
            ->map(fn (array $target) => data_get($target, 'Target.Id'));

        return $taskIps->every(fn (string $ip) => $healthyIps->contains($ip));
    }

    /**
     * Stage 2, bounded: the cluster reports converged — CONVERGED_SAMPLES
     * consecutive clean /health answers through the search host (each on its
     * own connection, so the ALB spreads them across every live target),
     * then a leader present per /debug. Only FAILED rounds spend the budget:
     * clean samples come a second apart and cost nothing, so a run of green
     * followed by one flap loses a round, never the whole wait — while a
     * cluster that keeps failing (or a leader that never confirms) still
     * exhausts the ROLL_GATE_ATTEMPTS × ROLL_GATE_INTERVAL_SECONDS patience.
     */
    protected function awaitClusterConverged(string $searchHost): bool
    {
        $consecutive = 0;

        for ($failedRounds = 0; $failedRounds < self::ROLL_GATE_ATTEMPTS;) {
            if ($this->healthSample($searchHost)) {
                $consecutive++;

                if ($consecutive < self::CONVERGED_SAMPLES) {
                    // Clean samples come fast — a second apart still lands each
                    // on a fresh connection for the ALB to spread.
                    $this->pause(1);

                    continue;
                }

                if ($this->leaderPresent($searchHost)) {
                    return true;
                }
            }

            // A failed sample, or a full clean run whose leader check didn't
            // confirm — either way the round failed: reset and spend a slot.
            $consecutive = 0;
            $failedRounds++;

            if ($failedRounds < self::ROLL_GATE_ATTEMPTS) {
                $this->pause(self::ROLL_GATE_INTERVAL_SECONDS);
            }
        }

        return false;
    }

    /**
     * One /health sample on its own connection. Only a 200 counts — a 503 is
     * a routed node saying it is degraded or catching up, and an unreachable
     * host says the public chain isn't serving; both mean "not converged yet".
     */
    protected function healthSample(string $searchHost): bool
    {
        try {
            $response = ($this->http ?? new Client())->get(sprintf('https://%s/health', $searchHost), [
                'headers' => ['Connection' => 'close'],
                'timeout' => 5,
                'http_errors' => false,
            ]);
        } catch (GuzzleException) {
            return false;
        }

        $this->clusterAnswered = true;

        return $response->getStatusCode() === 200;
    }

    /**
     * Whether whichever node answers /debug sees a raft leader — state 1 is
     * the leader itself, 4 a follower (which implies one). Needs the admin
     * key; when that's unreadable the leader check is skipped rather than
     * failing a roll the /health run has already vouched for.
     */
    protected function leaderPresent(string $searchHost): bool
    {
        $adminKey = Typesense::adminKey();

        if ($adminKey === null) {
            return true;
        }

        try {
            $response = ($this->http ?? new Client())->get(sprintf('https://%s/debug', $searchHost), [
                'headers' => ['X-TYPESENSE-API-KEY' => $adminKey, 'Connection' => 'close'],
                'timeout' => 5,
                'http_errors' => false,
            ]);
        } catch (GuzzleException) {
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        $state = json_decode((string) $response->getBody(), true)['state'] ?? null;

        return in_array($state, [1, 4], true);
    }

    /**
     * The between-attempts beat: tick the LongRunning heartbeat and sleep.
     * Isolated so tests can silence the real sleep.
     */
    protected function pause(int $seconds): void
    {
        WaitReporter::poll();

        sleep($seconds);
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
