<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Services;

use Dotenv\Dotenv;
use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Steps;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\EnvManifest;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Resources\WafV2\WebAcl;
use Codinglabs\Yolo\Resources\ElbV2\LoadBalancer;
use Codinglabs\Yolo\Resources\Ecs\ServicesCluster;
use Codinglabs\Yolo\Resources\CloudWatch\Dashboard;
use Codinglabs\Yolo\Resources\Ecs\TypesenseService;
use Codinglabs\Yolo\Resources\ElbV2\SearchTargetGroup;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Exceptions\ResourceDoesNotExistException;
use Codinglabs\Yolo\Resources\CloudWatchLogs\TypesenseLogGroup;

/**
 * Typesense — the environment's self-hosted search service. One three-node
 * Raft cluster per environment, shared by every consuming app: writes commit
 * on 2-of-3, a replaced node catches up from the surviving majority over the
 * network, so plain ephemeral Fargate storage is durable by replication
 * rather than by disk. Node count is hardcoded at three (Raft wants odd
 * quorums); the env-manifest entry declares version and per-node sizing.
 *
 * This definition is also the service's knowledge centre: the manifest entry's shape,
 * the admin key (seed-generated into the env-shared .env), the content-tagged
 * image, and the stable Cloud Map node addresses all resolve from here.
 */
class Typesense extends ServiceDefinition
{
    /** The node counts that make sense: 3 survives one loss, 5 survives two
     * (and spreads read load wider). Anything even pays for an extra node
     * without gaining the ability to lose another one, and 1 means any task
     * replacement loses the search data — so neither is offered. */
    public const array NODE_COUNTS = [3, 5];

    /** The typesense/typesense image tags we offer when configuring the cluster,
     * newest first — the configurator selects from these, defaulting to the
     * newest. We support v30+ only; there's no compelling reason to stand a
     * fresh cluster up on an older line, and one current stable is enough until
     * 31.x goes GA (added here then, alongside an app upgrade path). */
    public const array VERSIONS = ['30.2'];

    public const int API_PORT = 8108;

    public const int PEERING_PORT = 8107;

    /** The env-shared .env key holding the cluster's admin API key. */
    public const string ADMIN_KEY_NAME = 'TYPESENSE_API_KEY';

    /** @var string|null|false memoised admin key — false = not yet read */
    protected static string|null|false $adminKey = false;

    public function service(): Service
    {
        return Service::TYPESENSE;
    }

    public function description(): string
    {
        return 'Self-hosted search cluster (Typesense)';
    }

    public function envBacked(): bool
    {
        return true;
    }

    /**
     * Consuming typesense is env injection only — the app talks to the
     * cluster over HTTP with a scoped key, never to AWS — so there is no
     * runtime IAM to grant.
     */
    public function taskRoleStatements(): array
    {
        return [];
    }

    #[\Override]
    public function offerKeys(): array
    {
        return ['version', 'nodes', 'cpu', 'memory'];
    }

    #[\Override]
    public function offerDefaults(): array
    {
        return ['nodes' => 3, 'cpu' => 256, 'memory' => 1024];
    }

    /**
     * Version and node count are picked from fixed sets — a fresh cluster should
     * run the newest release on a quorum-valid topology, never an arbitrary
     * typed value — so both are selects. cpu/memory stay free-text (continuous
     * per-node sizing).
     */
    #[\Override]
    public function offerOptions(): array
    {
        return [
            'version' => self::VERSIONS,
            'nodes' => array_map(strval(...), self::NODE_COUNTS),
        ];
    }

    #[\Override]
    public function implications(): string
    {
        return 'Typesense runs a 3- or 5-node search cluster on Fargate, shared by every app in this environment — one task per node, billed continuously while provisioned. It comes up over a few minutes on the next sync, and changing the node count rolls the cluster one node at a time.';
    }

    /**
     * The manifest entry follows the tasks.* conventions: `version` is the
     * one required decision (an environment never runs an implicit search
     * engine version — and a YOLO upgrade must never imply one), while
     * `cpu`/`memory` are optional per-node sizing with the same value style
     * as tasks.web (quoted or bare numerics, sensible defaults).
     */
    #[\Override]
    public function validateOffer(mixed $offer, string $filename): void
    {
        parent::validateOffer($offer, $filename);

        if (! is_array($offer)) {
            $offer = [];
        }

        $version = $offer['version'] ?? null;

        if (! is_string($version) || trim($version) === '') {
            throw new IntegrityCheckException(sprintf(
                'services.typesense in %s must declare a version (the typesense/typesense image tag, e.g. "30.2").',
                $filename,
            ));
        }

        foreach (['cpu', 'memory'] as $key) {
            $value = $offer[$key] ?? null;

            if ($value !== null && (! is_numeric($value) || (int) $value <= 0)) {
                throw new IntegrityCheckException(sprintf(
                    'services.typesense.%s in %s must be a positive number of Fargate %s units (e.g. %s), like tasks.web.%s.',
                    $key,
                    $filename,
                    $key,
                    $key === 'cpu' ? "'256'" : "'1024'",
                    $key,
                ));
            }
        }

        $nodes = $offer['nodes'] ?? null;

        if ($nodes !== null && (! is_numeric($nodes) || ! in_array((int) $nodes, self::NODE_COUNTS, true))) {
            throw new IntegrityCheckException(sprintf(
                'services.typesense.nodes in %s must be 3 or 5 — an even count pays for an extra node without gaining the ability to lose another one, and a single node loses its search data whenever the task is replaced.',
                $filename,
            ));
        }
    }

    #[\Override]
    public function environmentSteps(): array
    {
        return [
            // Secrets + image first: the admin key seeds the env-shared .env,
            // the content-tagged image bakes it in — both inputs to the task
            // definition further down.
            Steps\Sync\Environment\SyncTypesenseAdminKeyStep::class,
            Steps\Sync\Environment\SyncTypesenseEcrRepositoryStep::class,
            Steps\Sync\Environment\BuildTypesenseImageStep::class,
            // The env services cluster hosts every env-shared service task;
            // typesense is its first occupant, so its lifecycle drives it for
            // now (move to the base env tier when a second service lands).
            Steps\Sync\Environment\SyncServicesClusterStep::class,
            Steps\Sync\Environment\SyncTypesenseLogGroupStep::class,
            // Stable node addresses: a private Cloud Map namespace + one DNS
            // service per node, so Raft peers survive task replacement.
            Steps\Sync\Environment\SyncTypesenseNamespaceStep::class,
            Steps\Sync\Environment\SyncTypesenseDiscoveryServicesStep::class,
            Steps\Sync\Environment\SyncTypesenseSecurityGroupStep::class,
            // The search target group precedes the nodes so they attach to it
            // at create — Typesense's /health doubles as readiness, dropping a
            // catching-up node out of rotation while the quorum serves.
            Steps\Sync\Environment\SyncSearchTargetGroupStep::class,
            Steps\Sync\Environment\SyncTypesenseTaskDefinitionStep::class,
            Steps\Sync\Environment\SyncTypesenseNodesStep::class,
            // Public ingress: the env-domain cert (SNI on the shared :443
            // listener), the search.{domain} rule and its Route 53 alias.
            Steps\Sync\Environment\SyncSearchCertificateStep::class,
            Steps\Sync\Environment\SyncSearchListenerRuleStep::class,
            Steps\Sync\Environment\SyncSearchRecordSetStep::class,
            Steps\Sync\Environment\SyncTypesenseAlarmsStep::class,
        ];
    }

    #[\Override]
    public function appSteps(): array
    {
        return [
            Steps\Sync\App\SyncTypesenseAppIngressStep::class,
            Steps\Sync\App\SyncTypesenseKeyStep::class,
        ];
    }

    /**
     * Build-time env injection for a consuming app: Scout's driver and prefix,
     * plus the private node addresses for server-side indexing (in-VPC, off
     * the ALB/WAF — bulk reimports never meet the rate limiter). The app's
     * scoped TYPESENSE_API_KEY is NOT injected here: sync:app mints it into
     * the app's .env.{environment}, where the build reads it like any other
     * env value. Browser config (the public host + a search-only key) is the
     * app's own concern.
     */
    #[\Override]
    public function buildValues(): array
    {
        return [
            'SCOUT_DRIVER' => 'typesense',
            'SCOUT_PREFIX' => Helpers::keyedResourceName() . '_',
            'TYPESENSE_HOST' => static::nodeAddress(0),
            'TYPESENSE_PORT' => (string) static::API_PORT,
            'TYPESENSE_PROTOCOL' => 'http',
            // Every node, host:port:protocol — for apps wiring Scout's client
            // with the full list for native client-side failover.
            'TYPESENSE_NODES' => implode(',', array_map(
                fn (int $node): string => sprintf('%s:%d:http', static::nodeAddress($node), static::API_PORT),
                range(0, static::nodes() - 1),
            )),
        ];
    }

    /**
     * The declared image version — the typesense/typesense tag.
     */
    public static function version(): ?string
    {
        $version = EnvManifest::get('services.typesense.version');

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * Per-node sizing, tasks.web-style: optional in the manifest, defaulting
     * to the seed shape (0.25 vCPU / 1 GB — comfortable for a few hundred MB
     * of index; Typesense wants RAM ≈ 2-3× the raw indexed size).
     */
    public static function cpu(): int
    {
        return (int) EnvManifest::get('services.typesense.cpu', 256);
    }

    public static function memory(): int
    {
        return (int) EnvManifest::get('services.typesense.memory', 1024);
    }

    /**
     * How many nodes the cluster runs — 3 (the default; survives one loss) or
     * 5 (survives two, spreads read load wider). Changing it is a manifest
     * edit + sync: the existing nodes roll onto the new peer list one at a
     * time, then sync adds or removes the difference.
     */
    public static function nodes(): int
    {
        return (int) EnvManifest::get('services.typesense.nodes', 3);
    }

    /**
     * The fewest healthy nodes that can still take writes — below this the
     * cluster is read-only until a node returns.
     */
    public static function quorumFloor(): int
    {
        return intdiv(static::nodes(), 2) + 1;
    }

    /**
     * The cluster's admin API key, read from the env-shared .env in the env
     * config bucket — null when not yet generated (SyncTypesenseAdminKeyStep
     * seeds it) or when the bucket/file doesn't exist yet (a greenfield plan
     * pass). Memoised so both sync passes see the same key.
     */
    public static function adminKey(): ?string
    {
        if (static::$adminKey !== false) {
            return static::$adminKey;
        }

        try {
            $body = (string) Aws::s3()->getObject([
                'Bucket' => Paths::s3EnvConfigBucket(),
                'Key' => Paths::s3SharedEnvKey(),
            ])['Body'];
        } catch (S3Exception $e) {
            if (S3::isNotFound($e)) {
                return static::$adminKey = null;
            }

            throw $e;
        }

        $key = Dotenv::parse($body)[static::ADMIN_KEY_NAME] ?? null;

        return static::$adminKey = (is_string($key) && $key !== '' ? $key : null);
    }

    /**
     * The content tag the image build pushes: the declared version + a
     * fingerprint of everything baked into the image (the admin key and the
     * peer list) — so a version bump, key rotation or node-count change
     * produces a new tag (and a task-def revision that rolls the nodes),
     * while unchanged inputs skip the build entirely. Null until the inputs
     * exist.
     */
    public static function imageTag(): ?string
    {
        $version = static::version();
        $key = static::adminKey();

        if ($version === null || $key === null) {
            return null;
        }

        return sprintf('%s-%s', $version, substr(hash('sha256', $key . '|' . implode(',', static::peers())), 0, 12));
    }

    /**
     * The stable private DNS name of one node.
     */
    public static function nodeAddress(int $node): string
    {
        return sprintf('typesense-%d.%s', $node, static::namespaceName());
    }

    /**
     * Every node's peering entry (host:peering_port:api_port) — identical on
     * all three nodes; each identifies itself by matching a local interface.
     *
     * @return array<int, string>
     */
    public static function peers(): array
    {
        return array_map(
            fn (int $node): string => sprintf('%s:%d:%d', static::nodeAddress($node), static::PEERING_PORT, static::API_PORT),
            range(0, static::nodes() - 1),
        );
    }

    /**
     * The environment's private service-discovery namespace.
     */
    public static function namespaceName(): string
    {
        return sprintf('%s.internal', Helpers::environment());
    }

    /**
     * The public search host — search.{domain} on the env manifest's canonical
     * domain, or null while no domain is declared.
     */
    public static function searchHost(): ?string
    {
        $domain = EnvManifest::get('domain');

        return is_string($domain) && $domain !== '' ? sprintf('search.%s', $domain) : null;
    }

    /**
     * The search host is the service's public face — an offered-and-claimed
     * typesense without an env domain is a misconfiguration, surfaced as a
     * hard error naming the fix rather than a silently private cluster.
     */
    public static function requireSearchHost(): string
    {
        $host = static::searchHost();

        if ($host === null) {
            throw new IntegrityCheckException(
                'services.typesense needs the environment manifest to declare `domain` (the search host is search.{domain}) — set it via `yolo environment:manifest:pull/push`.',
            );
        }

        return $host;
    }

    #[\Override]
    public function dashboardContext(): array
    {
        if (! Manifest::usesService(Service::TYPESENSE)) {
            return ['typesense' => null];
        }

        return ['typesense' => [
            'cluster' => (new ServicesCluster())->name(),
            'services' => array_map(
                fn (int $node): string => (new TypesenseService($node))->name(),
                range(0, static::nodes() - 1),
            ),
            'targetGroupSuffix' => static::tryDimension(fn (): string => Dashboard::targetGroupDimension((new SearchTargetGroup())->arn())),
            'albSuffix' => static::tryDimension(fn (): string => Dashboard::loadBalancerDimension((new LoadBalancer())->arn())),
            'logGroup' => (new TypesenseLogGroup())->name(),
        ]];
    }

    #[\Override]
    public function servicesWidgets(array $context): array
    {
        $typesense = $context['typesense'] ?? null;

        if ($typesense === null) {
            return [];
        }

        $region = $context['region'];
        $widgets = [];

        if ($typesense['targetGroupSuffix'] !== null && $typesense['albSuffix'] !== null) {
            $widgets[] = [
                'title' => 'Search node health (quorum needs 2)',
                'region' => $region,
                'view' => 'timeSeries',
                'stacked' => false,
                'period' => 60,
                'stat' => 'Minimum',
                'yAxis' => ['left' => ['min' => 0]],
                'metrics' => [
                    ['AWS/ApplicationELB', 'HealthyHostCount', 'TargetGroup', $typesense['targetGroupSuffix'], 'LoadBalancer', $typesense['albSuffix'], ['label' => 'Healthy', 'color' => Dashboard::GREEN]],
                    ['AWS/ApplicationELB', 'UnHealthyHostCount', 'TargetGroup', $typesense['targetGroupSuffix'], 'LoadBalancer', $typesense['albSuffix'], ['label' => 'Unhealthy', 'stat' => 'Maximum', 'color' => Dashboard::RED]],
                ],
                'annotations' => ['horizontal' => [
                    ['color' => Dashboard::RED, 'label' => 'Quorum floor', 'value' => static::quorumFloor(), 'fill' => 'below'],
                ]],
            ];

            $widgets[] = [
                'title' => 'Search requests + p99 latency',
                'region' => $region,
                'view' => 'timeSeries',
                'stacked' => false,
                'period' => 60,
                'metrics' => [
                    ['AWS/ApplicationELB', 'RequestCount', 'TargetGroup', $typesense['targetGroupSuffix'], 'LoadBalancer', $typesense['albSuffix'], ['stat' => 'Sum', 'label' => 'Requests', 'color' => Dashboard::BLUE]],
                    ['AWS/ApplicationELB', 'TargetResponseTime', 'TargetGroup', $typesense['targetGroupSuffix'], 'LoadBalancer', $typesense['albSuffix'], ['stat' => 'p99', 'label' => 'p99', 'yAxis' => 'right', 'color' => Dashboard::ORANGE]],
                ],
            ];
        }

        $nodeMetrics = [];

        foreach ($typesense['services'] as $index => $service) {
            $nodeMetrics[] = ['ECS/ContainerInsights', 'MemoryUtilized', 'ClusterName', $typesense['cluster'], 'ServiceName', $service, ['label' => sprintf('node %d memory', $index)]];
            $nodeMetrics[] = ['ECS/ContainerInsights', 'CpuUtilized', 'ClusterName', $typesense['cluster'], 'ServiceName', $service, ['label' => sprintf('node %d cpu', $index), 'yAxis' => 'right']];
        }

        $widgets[] = [
            'title' => 'Search nodes — memory (MB) + CPU (units)',
            'region' => $region,
            'view' => 'timeSeries',
            'stacked' => false,
            'period' => 60,
            'stat' => 'Average',
            'metrics' => $nodeMetrics,
        ];

        if (($context['wafWebAcl'] ?? null) !== null) {
            $widgets[] = [
                'title' => 'Search rate-limit blocks',
                'region' => $region,
                'view' => 'timeSeries',
                'stacked' => false,
                'period' => 300,
                'stat' => 'Sum',
                'metrics' => [
                    ['AWS/WAFV2', 'BlockedRequests', 'WebACL', $context['wafWebAcl'], 'Rule', WebAcl::SEARCH_RATE_RULE, 'Region', $region, ['label' => 'Blocked', 'color' => Dashboard::RED]],
                ],
            ];
        }

        return $widgets;
    }

    #[\Override]
    public function logPanels(array $context): array
    {
        return ['Typesense logs' => $context['typesense']['logGroup'] ?? null];
    }

    /**
     * Resolve a CloudWatch dimension from a live ARN, or null while the
     * backing resource doesn't exist yet (the widget is omitted until the
     * next sync).
     *
     * @param  callable(): string  $resolve
     */
    protected static function tryDimension(callable $resolve): ?string
    {
        try {
            return $resolve();
        } catch (ResourceDoesNotExistException) {
            return null;
        }
    }

    /**
     * Forget the memoised admin key — tests bind fresh S3 mocks per case.
     */
    public static function reset(): void
    {
        static::$adminKey = false;
    }
}
