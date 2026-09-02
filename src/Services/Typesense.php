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
 * The environment's self-hosted search cluster, shared by every consuming app.
 * Durability is by Raft replication (writes commit on a majority, a replaced
 * node catches up from the survivors), not by disk — plain ephemeral Fargate
 * storage is fine.
 */
class Typesense extends ServiceDefinition
{
    /** 3 survives one loss, 5 two. An even count pays for a node without gaining
     * fault tolerance, and 1 loses the search data on every task replacement. */
    public const array NODE_COUNTS = [3, 5];

    /** Offered image tags, newest first (the configurator defaults to the first). v30+ only. */
    public const array VERSIONS = ['30.2'];

    public const int API_PORT = 8108;

    public const int PEERING_PORT = 8107;

    public const string ADMIN_KEY_NAME = 'TYPESENSE_API_KEY';

    /**
     * Same literal as ADMIN_KEY_NAME but a different channel: the build injects
     * each app's own scoped key from env/.env.{app}. The admin key stays alone
     * in the env-shared `.env` and never reaches an app.
     */
    public const string CLIENT_KEY_NAME = 'TYPESENSE_API_KEY';

    /** Browser-embeddable: search-only, scoped to this app's `{prefix}*` collections. */
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

    /** The app talks to the cluster over HTTP with a scoped key, never to AWS — no runtime IAM. */
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

    /** Version and node count are selects, not free text: a fresh cluster must land on the newest release and a quorum-valid topology. */
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

    /** `version` is required: an environment never runs an implicit engine version, and a YOLO upgrade must never imply one. */
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
            // Admin key + image first — both feed the task definition below.
            Steps\Sync\Environment\SyncTypesenseAdminKeyStep::class,
            Steps\Sync\Environment\SyncTypesenseEcrRepositoryStep::class,
            Steps\Sync\Environment\BuildTypesenseImageStep::class,
            // Typesense is the services cluster's first occupant, so its lifecycle
            // drives it for now (move to the base env tier when a second service lands).
            Steps\Sync\Environment\SyncServicesClusterStep::class,
            Steps\Sync\Environment\SyncTypesenseLogGroupStep::class,
            // Cloud Map gives each node a stable address so Raft peers survive task replacement.
            Steps\Sync\Environment\SyncTypesenseNamespaceStep::class,
            Steps\Sync\Environment\SyncTypesenseDiscoveryServicesStep::class,
            Steps\Sync\Environment\SyncTypesenseSecurityGroupStep::class,
            // Ingress precedes the nodes: ECS CreateService rejects a target group
            // not yet associated with a load balancer (association comes from a
            // forwarding listener rule), and the nodes step's roll gate probes the
            // public search host — so the cert (which also bootstraps the shared
            // :443 listener), rule and Route 53 alias must all exist before any
            // node rolls, or a domain change aborts the roll every time.
            Steps\Sync\Environment\SyncSearchTargetGroupStep::class,
            Steps\Sync\Environment\SyncSearchCertificateStep::class,
            Steps\Sync\Environment\SyncSearchListenerRuleStep::class,
            Steps\Sync\Environment\SyncSearchRecordSetStep::class,
            Steps\Sync\Environment\SyncTypesenseTaskDefinitionStep::class,
            Steps\Sync\Environment\SyncTypesenseNodesStep::class,
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

    /** The minted keys ride env/.env.{app}, which destroy:app removes wholesale — only the SG ingress needs a step. */
    #[\Override]
    public function teardownAppSteps(): array
    {
        return [
            Steps\Destroy\App\RevokeTypesenseIngressStep::class,
        ];
    }

    /**
     * Not the inverse of environmentSteps: the cluster delete drains the node
     * services first (freeing the target group's targets and the Cloud Map
     * instances), the listener rule precedes the target group it forwards to,
     * and the namespace cascades its discovery services. The shared :443
     * listener and apex cert are env-shell, so neither appears here.
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
     * Indexing goes private (in-VPC node addresses, off the ALB/WAF so bulk
     * reimports never meet the rate limiter); browser search goes public via
     * search.{domain}. The app's scoped keys are NOT injected here — sync:app
     * mints them into env/.env.{app}, merged in separately, so this stays
     * manifest-derived with no live AWS read. The search host is required
     * because a claimed typesense with no env domain can't serve browsers at all.
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
            // Full node list for native client-side failover.
            'TYPESENSE_NODES' => implode(',', array_map(
                fn (int $node): string => sprintf('%s:%d:http', static::nodeAddress($node), static::API_PORT),
                range(0, static::nodes() - 1),
            )),
            'TYPESENSE_SEARCH_HOST' => static::requireSearchHost(),
            'TYPESENSE_SEARCH_PORT' => '443',
            'TYPESENSE_SEARCH_PROTOCOL' => 'https',
        ];
    }

    public static function version(): ?string
    {
        $version = EnvManifest::get('services.typesense.version');

        return is_string($version) && $version !== '' ? $version : null;
    }

    /** Defaults to 0.25 vCPU / 1 GB — comfortable for a few hundred MB of index; Typesense wants RAM ≈ 2-3× the raw indexed size. */
    public static function cpu(): int
    {
        return (int) EnvManifest::get('services.typesense.cpu', 256);
    }

    public static function memory(): int
    {
        return (int) EnvManifest::get('services.typesense.memory', 1024);
    }

    /** Changing it rolls the existing nodes onto the new peer list one at a time, then sync adds or removes the difference. */
    public static function nodes(): int
    {
        return (int) EnvManifest::get('services.typesense.nodes', 3);
    }

    /** Below this the cluster is read-only until a node returns. */
    public static function quorumFloor(): int
    {
        return intdiv(static::nodes(), 2) + 1;
    }

    /**
     * Null until SyncTypesenseAdminKeyStep seeds it, or while the bucket/file
     * doesn't exist (a greenfield plan pass). Memoised so both sync passes see
     * the same key.
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
     * Null until SyncTypesenseKeyStep mints it. The search key is minted in the
     * same pass, so this is the pair's once-minted marker (the key step then
     * verifies against the cluster — stored values are value-truth, the cluster
     * is honour-truth). Deliberately not memoised: it must reflect the live object.
     */
    public static function appKey(): ?string
    {
        return static::appEnvValue(static::CLIENT_KEY_NAME);
    }

    /** The browser-safe half of the minted pair, and the one the key step probes the cluster with. */
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
     * Returned whole so imageTag() fingerprints it — any change (a rotated key,
     * a flipped setting) re-tags the image and rolls the nodes. The nodes file
     * is NOT baked: {@see entrypointScript} writes it at runtime so Typesense
     * only ever reads IPs and never resolves DNS itself. CORS is any-origin on
     * purpose: the browser key is search-only and public by design, so an origin
     * allowlist guards nothing off-browser — the real controls are that key's
     * scope and the per-IP WAF rate limit on search.{domain}.
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
     * Version + a fingerprint of everything baked into the image, so any input
     * change produces a new tag (and a task-def revision that rolls the nodes)
     * while unchanged inputs skip the build entirely. Null until the inputs exist.
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
     * Fail-closed peer resolution. Typesense's own peer refresh treats every DNS
     * answer as new raft membership, so a transient resolver failure becomes a
     * peer-list rewrite that braft replicates as a fatal empty-peers entry —
     * every node dies in turn and a fresh empty cluster comes up behind a green
     * /health (typesense/typesense#2189, #2238). The wrapper resolves the baked
     * hostname list itself and rewrites the nodes file only when EVERY peer resolves.
     *
     * Boot is the exception: a dead sibling has no DNS record, so waiting for
     * the full set deadlocks every replacement node. Booting needs only self
     * plus one other peer; below that floor the entrypoint exits for a fresh
     * task after a bounded window, because a self-only list can elect a
     * one-node raft over an empty disk — the exact failure this prevents.
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
        readonly BOOT_TIMEOUT_SECONDS=120

        # Resolve every baked host:peering:api entry to ip:peering:api, printing
        # the comma-joined entries that DID resolve. Exit status is the contract:
        # 0 only when every host resolved — the refresh loop keys off it so a
        # partial round never rewrites standing membership; the boot gate reads
        # the partial output instead.
        resolve_peers() {
            local entries entry host ports ip resolved=() missed=0

            IFS=',' read -ra entries < "$PEERS_FILE"

            for entry in "${entries[@]}"; do
                host=${entry%%:*}
                ports=${entry#*:}
                ip=$(getent ahostsv4 "$host" | awk '{ print $1; exit }') || true

                if [[ -z ${ip:-} ]]; then
                    missed=1
                    continue
                fi

                resolved+=("$ip:$ports")
            done

            if (( ${#resolved[@]} > 0 )); then
                (IFS=','; printf '%s' "${resolved[*]}")
            fi

            return "$missed"
        }

        # This task's own interface addresses, space-separated. ECS (awsvpc)
        # writes the task IP against the container hostname in /etc/hosts, so
        # the getent fallback holds even where hostname -I is unavailable.
        local_addresses() {
            hostname -I 2>/dev/null || getent hosts "$(hostname)" | awk '{ printf "%s ", $1 }'
        }

        # Whether a (possibly partial) resolved list is enough to BOOT on: it
        # names this node itself — an entry resolving to a local address, so
        # Typesense can identify itself in the peer list — plus at least one
        # other peer to join. Anything less and we keep waiting (bounded).
        bootable() {
            local entries entry ip self=0 others=0 locals

            [[ -z $1 ]] && return 1

            locals=" $(local_addresses) "

            IFS=',' read -ra entries <<< "$1"

            for entry in "${entries[@]}"; do
                ip=${entry%%:*}

                if [[ $locals == *" $ip "* ]]; then
                    self=1
                else
                    others=$(( others + 1 ))
                fi
            done

            (( self == 1 && others >= 1 ))
        }

        # Atomic same-filesystem replace so Typesense never reads a partial file.
        write_if_changed() {
            if [[ ! -f $NODES_FILE || $1 != "$(cat "$NODES_FILE")" ]]; then
                printf '%s' "$1" > "$NODES_FILE.tmp" && mv "$NODES_FILE.tmp" "$NODES_FILE"
            fi
        }

        # Boot gate: self + at least one other peer — enough to join. Requiring
        # the FULL set here would deadlock on a dead sibling (no task, no DNS
        # record), so full-set discipline belongs to the refresh loop alone.
        # But below the join floor the gate never opens: past the bounded
        # window, exit for a fresh task instead — a below-quorum nodes file is
        # worse than a restart, because a self-only list can elect a one-node
        # raft over an empty ephemeral disk.
        boot_deadline=$(( SECONDS + BOOT_TIMEOUT_SECONDS ))

        while true; do
            if nodes=$(resolve_peers); then
                break
            fi

            if bootable "$nodes"; then
                echo "typesense-entrypoint: booting on a partial peer list ($nodes) — the refresh loop restores full membership as the rest resolve" >&2
                break
            fi

            if (( SECONDS >= boot_deadline )); then
                echo "typesense-entrypoint: boot gate timed out after ${BOOT_TIMEOUT_SECONDS}s without this node plus a peer resolving (got: ${nodes:-nothing}) — exiting for a fresh task rather than forming a below-quorum cluster" >&2
                exit 1
            fi

            echo "typesense-entrypoint: waiting for this node plus at least one peer in $PEERS_FILE to resolve" >&2
            sleep 5
        done

        write_if_changed "$nodes"

        (
            while true; do
                sleep "$REFRESH_SECONDS"

                if nodes=$(resolve_peers); then
                    write_if_changed "$nodes"
                fi
            done
        ) &

        exec /opt/typesense-server "$@"

        BASH;
    }

    public static function nodeAddress(int $node): string
    {
        return sprintf('typesense-%d.%s', $node, static::namespaceName());
    }

    /**
     * Identical on every node; each identifies itself by matching a local interface.
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

    public static function namespaceName(): string
    {
        return sprintf('%s.internal', Helpers::environment());
    }

    public static function searchHost(): ?string
    {
        $domain = EnvManifest::get('domain');

        return is_string($domain) && $domain !== '' ? sprintf('search.%s', $domain) : null;
    }

    /** A claimed typesense without an env domain is a misconfiguration — hard-fail naming the fix rather than run a silently private cluster. */
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
                    ['color' => Dashboard::RED, 'label' => 'Quorum floor', 'value' => static::quorumFloor()],
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

    /** The search rate-limit rule is a WebACL rule, so it charts with the WAF posture, not under Services. */
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
     * Null while the backing resource doesn't exist yet — the widget is omitted until the next sync.
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

    /** Tests bind fresh S3 mocks per case. */
    public static function reset(): void
    {
        static::$adminKey = false;
    }
}
