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

    /**
     * The app-facing env var a consuming app reads at runtime — the same
     * literal as the admin key's name, but a different channel: the build
     * injects each app's own scoped key here from its environment-side per-app
     * `.env` (env/.env.{app} in the env config bucket). The admin key never
     * reaches an app — it stays alone in the env-shared `.env`.
     */
    public const string CLIENT_KEY_NAME = 'TYPESENSE_API_KEY';

    /**
     * The app-facing env var holding the browser search key — a search-only
     * scoped key sync:app mints alongside the server-side CLIENT_KEY_NAME and
     * writes into the same environment-side per-app `.env`. Safe to embed in
     * the page: it can only run searches, and only over this app's own
     * `{prefix}*` collections.
     */
    public const string SEARCH_KEY_NAME = 'TYPESENSE_SEARCH_KEY';

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
            // Public ingress is wired BEFORE the nodes, because the node services
            // are load-balanced: ECS CreateService rejects a target group that
            // isn't yet associated with a load balancer, and a target group only
            // becomes associated once a listener rule forwards to it. So the target
            // group, the env-domain cert (which also bootstraps the shared :443
            // listener — the service owns its ingress, never waiting on an app) and
            // the search.{domain} rule all precede the node services. /health then
            // doubles as readiness, dropping a catching-up node out of rotation
            // while the quorum keeps serving.
            Steps\Sync\Environment\SyncSearchTargetGroupStep::class,
            Steps\Sync\Environment\SyncSearchCertificateStep::class,
            Steps\Sync\Environment\SyncSearchListenerRuleStep::class,
            Steps\Sync\Environment\SyncTypesenseTaskDefinitionStep::class,
            Steps\Sync\Environment\SyncTypesenseNodesStep::class,
            // The Route 53 alias + the healthy-host alarms follow — neither gates
            // service creation.
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
     * Revoke this app's 8108 ingress from the env-shared node SG — the mirror of
     * SyncTypesenseAppIngressStep. The minted keys it also wrote ride the app's
     * per-app env file (env/.env.{app}), which destroy:app removes wholesale, so
     * they need no service-specific step here.
     */
    #[\Override]
    public function teardownAppSteps(): array
    {
        return [
            Steps\Destroy\App\RevokeTypesenseIngressStep::class,
        ];
    }

    /**
     * Teardown order (not the inverse of environmentSteps): the cluster delete
     * drains and removes the node services first (freeing the target group's
     * targets and the Cloud Map instances), the listener rule goes before the
     * target group it forwards to, and the namespace delete cascades its own
     * discovery services. The shared :443 listener + the apex cert are env-shell
     * (torn down after services) / deliberately kept, so neither appears here.
     */
    #[\Override]
    public function teardownEnvironmentSteps(): array
    {
        return [
            Steps\Sync\Environment\SyncTypesenseAlarmsStep::class,
            Steps\Sync\Environment\SyncSearchRecordSetStep::class,
            Steps\Sync\Environment\SyncSearchListenerRuleStep::class,
            Steps\Sync\Environment\SyncServicesClusterStep::class,
            Steps\Sync\Environment\SyncSearchTargetGroupStep::class,
            Steps\Sync\Environment\SyncTypesenseSecurityGroupStep::class,
            Steps\Sync\Environment\SyncTypesenseNamespaceStep::class,
            Steps\Sync\Environment\SyncTypesenseLogGroupStep::class,
            Steps\Sync\Environment\SyncTypesenseEcrRepositoryStep::class,
        ];
    }

    /**
     * Build-time env injection for a consuming app, both traffic paths:
     *
     * - Server-side indexing (private, in-VPC, off the ALB/WAF — bulk reimports
     *   never meet the rate limiter): Scout's driver and prefix, plus the
     *   private Cloud Map node addresses.
     * - Browser-direct search (public): the search host (search.{domain} on the
     *   shared :443 listener) over https, paired with the search-only key
     *   sync:app mints into the app's environment-side `.env`.
     *
     * The app's scoped keys are NOT injected here — sync:app mints them into
     * env/.env.{app} and the build step merges that file in separately, so this
     * definition stays pure (manifest-derived, no live AWS read). A claimed
     * typesense with no env domain can't serve browsers at all, so the search
     * host is required here rather than shipping a dead search box.
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
            'TYPESENSE_SEARCH_HOST' => static::requireSearchHost(),
            'TYPESENSE_SEARCH_PORT' => '443',
            'TYPESENSE_SEARCH_PROTOCOL' => 'https',
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
     * This app's scoped server-side key, read from its environment-side per-app
     * `.env` (env/.env.{app}) in the env config bucket — null until
     * SyncTypesenseKeyStep mints it, or while the bucket/file doesn't exist yet
     * (a greenfield env). The search-only key is minted in the same pass, so a
     * present server key implies a present search key; this is the pair's
     * once-minted marker (which the key step then verifies against the cluster —
     * stored values are value-truth, the cluster is honour-truth). Read fresh
     * every time (no memoisation): it must reflect the live object, not a stale
     * per-process cache.
     */
    public static function appKey(): ?string
    {
        return static::appEnvValue(static::CLIENT_KEY_NAME);
    }

    /**
     * This app's scoped search-only key from the same environment-side per-app
     * `.env` — the browser-safe half of the minted pair, and the one the key
     * step probes the cluster with.
     */
    public static function appSearchKey(): ?string
    {
        return static::appEnvValue(static::SEARCH_KEY_NAME);
    }

    protected static function appEnvValue(string $name): ?string
    {
        try {
            $body = (string) Aws::s3()->getObject([
                'Bucket' => Paths::s3EnvConfigBucket(),
                'Key' => Paths::s3EnvAppEnvKey(),
            ])['Body'];
        } catch (S3Exception $e) {
            if (S3::isNotFound($e)) {
                return null;
            }

            throw $e;
        }

        $value = Dotenv::parse($body)[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The server config (typesense-server.ini) baked into the node image: the
     * admin key, the API/peering ports, the peer-list pointer, and CORS enabled
     * so browsers can query the public search host directly. Returned whole so
     * imageTag() fingerprints it — any change here (a rotated key, a flipped
     * setting) re-tags the image and rolls the nodes, where fingerprinting only
     * the key would let an edited config ship under the old tag and never
     * deploy.
     *
     * The nodes file it points at is NOT baked — {@see entrypointScript} writes
     * it at runtime from the baked hostname peer list, so Typesense only ever
     * reads IPs and never resolves DNS itself.
     *
     * CORS is any-origin on purpose: the key the browser carries is a
     * search-only scoped key (public by design — it ships in the page), so an
     * origin allowlist guards nothing a determined caller can't sidestep
     * off-browser. The real controls are that key's scope and the per-IP WAF
     * rate limit on search.{domain}.
     */
    public static function serverConfig(): string
    {
        return implode("\n", [
            '[server]',
            'api-address = 0.0.0.0',
            sprintf('api-port = %d', static::API_PORT),
            sprintf('peering-port = %d', static::PEERING_PORT),
            'data-dir = /tmp',
            sprintf('api-key = %s', static::adminKey()),
            'nodes = /etc/typesense/nodes',
            'enable-cors = true',
            '',
        ]);
    }

    /**
     * The content tag the image build pushes: the declared version + a
     * fingerprint of everything baked into the image (the server config — which
     * carries the admin key and CORS — the peer list, and the entrypoint
     * script) — so a version bump, key rotation, config change, node-count
     * change or entrypoint change produces a new tag (and a task-def revision
     * that rolls the nodes), while unchanged inputs skip the build entirely.
     * Null until the inputs exist.
     */
    public static function imageTag(): ?string
    {
        $version = static::version();
        $key = static::adminKey();

        if ($version === null || $key === null) {
            return null;
        }

        return sprintf('%s-%s', $version, substr(hash('sha256', static::serverConfig() . '|' . implode(',', static::peers()) . '|' . static::entrypointScript()), 0, 12));
    }

    /**
     * The fail-closed peer-resolution entrypoint baked into the node image.
     * Typesense's own peer refresh treats every DNS answer as new raft
     * membership truth, so a transient resolver failure becomes a peer-list
     * rewrite — which braft appends as a fatal, REPLICATING empty-peers config
     * entry that can kill every node in turn and leave a fresh empty cluster
     * behind a green /health (typesense/typesense#2189, #2238).
     * The wrapper takes DNS out of Typesense's hands entirely: it resolves the
     * baked hostname peer list itself and (re)writes the nodes file Typesense
     * watches only when EVERY peer resolves, so a failed or partial round
     * leaves the last-known-good membership standing. Boot blocks until the
     * full peer set resolves once — a node that can't see its peers can't form
     * a quorum anyway, and /health stays red while it waits. A stale IP after
     * a task replacement heals on the next successful round.
     */
    public static function entrypointScript(): string
    {
        return <<<'BASH'
        #!/usr/bin/env bash
        # Fail-closed peer resolution: Typesense only ever reads IPs from the
        # nodes file, never hostnames — so its internal DNS re-resolution (which
        # rewrites raft membership on whatever a round returns, including a
        # transient resolver failure) never runs. This wrapper owns resolution
        # instead, and only rewrites the nodes file when every peer resolves.
        set -u

        readonly PEERS_FILE=/etc/typesense/peers
        readonly NODES_FILE=/etc/typesense/nodes
        readonly REFRESH_SECONDS=15

        # Resolve every baked host:peering:api entry to ip:peering:api. Prints
        # the full comma-joined list only when every host resolved; any miss
        # returns non-zero so the caller leaves the nodes file untouched.
        resolve_all() {
            local entries entry host ports ip resolved=()

            IFS=',' read -ra entries < "$PEERS_FILE"

            for entry in "${entries[@]}"; do
                host=${entry%%:*}
                ports=${entry#*:}
                ip=$(getent ahostsv4 "$host" | awk '{ print $1; exit }') || true

                if [[ -z ${ip:-} ]]; then
                    return 1
                fi

                resolved+=("$ip:$ports")
            done

            (IFS=','; printf '%s' "${resolved[*]}")
        }

        # Atomic same-filesystem replace so Typesense never reads a partial file.
        write_if_changed() {
            if [[ ! -f $NODES_FILE || $1 != "$(cat "$NODES_FILE")" ]]; then
                printf '%s' "$1" > "$NODES_FILE.tmp" && mv "$NODES_FILE.tmp" "$NODES_FILE"
            fi
        }

        until nodes=$(resolve_all); do
            echo "typesense-entrypoint: waiting for every peer in $PEERS_FILE to resolve" >&2
            sleep 5
        done

        write_if_changed "$nodes"

        (
            while true; do
                sleep "$REFRESH_SECONDS"

                if nodes=$(resolve_all); then
                    write_if_changed "$nodes"
                fi
            done
        ) &

        exec /opt/typesense-server "$@"

        BASH;
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

        return $widgets;
    }

    /**
     * The search host's own WAF rate-limit rule charts its blocks in the `# WAF`
     * group, not `# Services` — it's a WebACL rule, so it belongs with the rest
     * of the WAF posture. Omitted until both Typesense is consumed and the env
     * WebACL is resolved.
     */
    #[\Override]
    public function wafPanels(array $context): array
    {
        if (($context['typesense'] ?? null) === null || ($context['wafWebAcl'] ?? null) === null) {
            return [];
        }

        return [[
            'title' => 'Search rate-limit blocks',
            'region' => $context['region'],
            'view' => 'timeSeries',
            'stacked' => false,
            'period' => 300,
            'stat' => 'Sum',
            'metrics' => [
                ['AWS/WAFV2', 'BlockedRequests', 'WebACL', $context['wafWebAcl'], 'Rule', WebAcl::SEARCH_RATE_RULE, 'Region', $context['region'], ['label' => 'Blocked', 'color' => Dashboard::RED]],
            ],
        ]];
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
