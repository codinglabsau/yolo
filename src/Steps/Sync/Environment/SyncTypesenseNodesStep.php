<?php

namespace Codinglabs\Yolo\Steps\Sync\Environment;

use RuntimeException;
use GuzzleHttp\Client;
use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Change;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Aws\ElbV2;
use Codinglabs\Yolo\DeployCheck;
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
use Codinglabs\Yolo\Services\TypesenseTaskDefinition;
use Codinglabs\Yolo\Resources\ElbV2\SearchTargetGroup;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Resources\ServiceDiscovery\TypesenseDiscoveryService;

/**
 * Reconciled with the consensus protocol in mind: stale nodes roll first,
 * strictly one at a time, each waiting for CLUSTER truth (target healthy,
 * /health clean, leader present) rather than ECS stability — "services
 * stable" only proves a task launched, and on an ephemeral-disk cluster two
 * nodes down at once shrinks the quorum into total data loss, so a node that
 * never rejoins ABORTS the roll. Missing nodes are then created together (a
 * majority must be up before the cluster can form), and surplus nodes are
 * deleted last, after the survivors' rolled peer list has dropped them.
 *
 * Full teardown is left to the services-cluster step: AWS refuses to delete a
 * cluster with active services, so its delete cascades these.
 */
class SyncTypesenseNodesStep implements LongRunning, Step
{
    use RecordsChanges;
    use RecordsWarnings;

    /**
     * Generous: a replacement replays the whole dataset from the surviving
     * majority before /health goes green.
     */
    protected const int ROLL_GATE_ATTEMPTS = 60;

    protected const int ROLL_GATE_INTERVAL_SECONDS = 10;

    /**
     * The ALB spreads one-request connections across ALL live targets, and the
     * liveness health check keeps a catching-up node (answering 503) in
     * rotation — so a clean run this long means no routed node is still lagging.
     */
    protected const int CONVERGED_SAMPLES = 12;

    public function __construct(protected string $environment = '', protected ?Client $http = null) {}

    /** Whether any roll-gate sample got an HTTP answer at all — an abort with
     * none points at operator-side reachability, not the cluster. */
    protected bool $clusterAnswered = false;

    protected ?string $rollPosition = null;

    public function __invoke(array $options): StepResult
    {
        if (Lifecycle::state(Service::TYPESENSE) !== ServiceState::Provision) {
            return StepResult::SKIPPED;
        }

        $dryRun = (bool) Arr::get($options, 'dry-run');

        [$missing, $stale, $surplus] = $this->partition(planning: $dryRun);

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

        // ECS CreateService rejects a load-balanced service until a listener rule
        // forwards to the target group; on a greenfield env the :443 listener may
        // still be bootstrapping, so defer the missing nodes to the next sync
        // (changes are already recorded, so the plan still shows them).
        if ($missing !== [] && ! $this->searchTargetGroupAttached()) {
            return StepResult::SKIPPED;
        }

        // Survivors first: the rolled image carries the new peer list, so the
        // standing quorum knows about joiners (and forgets leavers) before either happens.
        foreach (array_values($stale) as $index => $service) {
            $this->rollPosition = sprintf('Node %d of %d (%s)', $index + 1, count($stale), $service->name());

            $this->report('ECS replacing the task');

            $service->adoptLatestRevision();

            $this->waitForStability([$service->name()]);

            $this->assertNodeRejoined($service, rolled: $index + 1, total: count($stale));
        }

        $this->rollPosition = null;

        foreach ($missing as $service) {
            $service->create();
        }

        if ($missing !== []) {
            $this->report(sprintf('Creating %s · waiting for ECS stability', Str::plural('missing node', count($missing), true)));

            $this->waitForStability(array_map(fn (TypesenseService $service): string => $service->name(), $missing));
        }

        // Then shrink: the surplus nodes are no longer in anyone's peer list.
        foreach ($surplus as $service) {
            $this->report(sprintf('Removing surplus node %s', $service->name()));

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
     * On the plan pass the task-definition step hasn't registered yet, so the
     * live latest is still the old revision and every node would read current —
     * the roll would be pruned from apply. A pending registration therefore
     * stales every existing node on the plan; apply re-partitions against the
     * registered revision and reaches the same set.
     *
     * @return array{0: array<int, TypesenseService>, 1: array<int, TypesenseService>, 2: array<int, TypesenseService>}
     */
    protected function partition(bool $planning): array
    {
        $missing = [];
        $existing = [];
        $stale = [];
        $surplus = [];

        $latest = $this->latestRevisionArn();

        foreach (range(0, Typesense::nodes() - 1) as $node) {
            $service = new TypesenseService($node);

            if (! $service->exists()) {
                $missing[] = $service;

                continue;
            }

            $existing[] = $service;

            if ($latest !== null && $service->current()['taskDefinition'] !== $latest) {
                $stale[] = $service;
            }
        }

        if ($planning && $existing !== [] && $this->revisionRegistersThisSync()) {
            $stale = $existing;
        }

        // range() counts DOWN when from > to, so at the maximum count this must not run at all.
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
     * Two bounded stages: the replacement's own target turns healthy (the ECS
     * waiter can pass while the container still sits in its entrypoint boot
     * gate), then the cluster reports converged through the public search host.
     * On timeout the roll aborts with the remaining nodes untouched — rolling
     * past a node that never rejoined is how one lost node becomes a lost cluster.
     */
    protected function assertNodeRejoined(TypesenseService $service, int $rolled, int $total): void
    {
        if (! $this->awaitReplacementServing($service)) {
            $this->abortRoll($service, $rolled, $total, 'its replacement task never turned healthy in the search target group (likely still waiting on peer DNS in its boot gate)');
        }

        $searchHost = Typesense::searchHost();

        if ($searchHost === null) {
            // Unreachable in practice (the target-group step hard-requires the search
            // host), but a missing host must not fail a roll the operator can't act on mid-flight.
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
     * Target health is process liveness — "healthy" means the boot gate passed
     * and the API answers, not that the node caught up (that's stage 2).
     */
    protected function awaitReplacementServing(TypesenseService $service): bool
    {
        for ($attempt = 1; $attempt <= self::ROLL_GATE_ATTEMPTS; $attempt++) {
            $this->report(sprintf('waiting for the replacement to serve · attempt %d/%d', $attempt, self::ROLL_GATE_ATTEMPTS));

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
     * Each sample rides its own connection so the ALB spreads them across every
     * live target. Only FAILED rounds spend the budget: clean samples come a
     * second apart and cost nothing, so a green run broken by one flap loses a
     * round, not the whole wait, while a cluster that keeps failing still exhausts it.
     */
    protected function awaitClusterConverged(string $searchHost): bool
    {
        $consecutive = 0;

        for ($failedRounds = 0; $failedRounds < self::ROLL_GATE_ATTEMPTS;) {
            $this->report(sprintf(
                'serving · %d/%d clean /health samples%s',
                $consecutive,
                self::CONVERGED_SAMPLES,
                $failedRounds > 0 ? sprintf(' · %s', Str::plural('failed round', $failedRounds, true)) : '',
            ));

            if ($this->healthSample($searchHost)) {
                $consecutive++;

                if ($consecutive < self::CONVERGED_SAMPLES) {
                    $this->pause(1);

                    continue;
                }

                if ($this->leaderPresent($searchHost)) {
                    return true;
                }
            }

            $consecutive = 0;
            $failedRounds++;

            if ($failedRounds < self::ROLL_GATE_ATTEMPTS) {
                $this->pause(self::ROLL_GATE_INTERVAL_SECONDS);
            }
        }

        return false;
    }

    /**
     * Only a 200 counts — a 503 is a routed node degraded or catching up, and an
     * unreachable host means the public chain isn't serving.
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
     * /debug state 1 is the leader, 4 a follower (which implies one). Without the
     * admin key the check is skipped rather than failing a roll /health has vouched for.
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

    /** Isolated so tests can silence the real sleep. */
    protected function pause(int $seconds): void
    {
        WaitReporter::poll();

        sleep($seconds);
    }

    /**
     * An elapsed timer alone reads as hung across minutes of bounded polls, so
     * each phase names itself on the progress line.
     */
    protected function report(string $message): void
    {
        WaitReporter::line($this->rollPosition === null ? $message : sprintf('%s · %s', $this->rollPosition, $message));
        WaitReporter::poll();
    }

    /**
     * The Cloud Map delete waits out the instance deregistration — AWS refuses
     * it while that is still settling.
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
     * ECS refuses a load-balanced service against a target group no listener
     * rule forwards to yet; an absent target group reads as not-yet-attached.
     */
    protected function searchTargetGroupAttached(): bool
    {
        try {
            return (ElbV2::targetGroup((new SearchTargetGroup())->name())['LoadBalancerArns'] ?? []) !== [];
        } catch (ResourceDoesNotExistException) {
            return false;
        }
    }

    /**
     * Never under the deploy gate's read-tier check: that gate skips the
     * task-definition step (rendering the desired revision reads the admin key
     * the tier is fenced from), so nothing registers there.
     */
    protected function revisionRegistersThisSync(): bool
    {
        return ! DeployCheck::active() && TypesenseTaskDefinition::registrationPending();
    }

    protected function latestRevisionArn(): ?string
    {
        return TypesenseTaskDefinition::live()['taskDefinitionArn'] ?? null;
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
