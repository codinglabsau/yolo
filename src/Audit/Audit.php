<?php

namespace Codinglabs\Yolo\Audit;

use Codinglabs\Yolo\Aws;

/**
 * Pure classification for `yolo audit`. Given the resources tagged for an
 * environment (from the Resource Groups Tagging API) and the set of apps that
 * are currently live (those with a running Fargate cluster), it attributes each
 * resource to an app and flags the ones that can't be accounted for.
 *
 * No AWS calls here — the command does the I/O and feeds the data in, so this is
 * all unit-testable in isolation.
 */
class Audit
{
    public const APP_TAG = 'yolo:app';

    public const SCOPE_TAG = 'yolo:scope';

    private const string NAME_TAG = 'Name';

    public const STATUS_OK = 'ok';

    public const STATUS_UNEXPECTED = 'unexpected';

    /**
     * Why an `unexpected` resource isn't accounted for — surfaced in the audit's
     * Reason column. Audit only inspects tags, the ARN service and whether the
     * owning app's cluster is live; it never compares a resource's configuration
     * against the manifest (that's `sync`'s job), so none of these is a config
     * concern — each is an ownership/inventory fact.
     */
    public const REASON_DEAD_APP = 'app cluster gone';

    public const REASON_UNMANAGED_SERVICE = 'service no longer provisioned';

    public const REASON_NO_OWNER = 'no ownership tag';

    public const SCOPE_ACCOUNT = 'account';

    public const SCOPE_ENV = 'env';

    public const SCOPE_APP = 'app';

    /**
     * The AWS services YOLO provisions, keyed by their `src/Resources/{group}`
     * directory. A YOLO-owned resource (one carrying a `yolo:app` or
     * `yolo:scope` marker) whose ARN service is *not* one of these is an
     * `orphan` — YOLO created it once but no longer has a Resource class for
     * that service, so it would never appear in a sync plan. The DynamoDB
     * sessions table left behind when DynamoDB support was removed is the
     * canonical case.
     *
     * Keys mirror the `src/Resources/*` directories one-for-one (enforced by
     * ManagedServicesTest), so this stays correct by construction: dropping a
     * service directory automatically surfaces its leftover resources as
     * orphans, and adding one fails the test until it is catalogued here —
     * which stops a newly-supported service from being false-flagged.
     *
     * @var array<string, string>
     */
    public const SERVICE_BY_RESOURCE_GROUP = [
        'Acm' => 'acm',
        'ApplicationAutoScaling' => 'application-autoscaling',
        'CloudFront' => 'cloudfront',
        'CloudWatch' => 'cloudwatch',
        'CloudWatchLogs' => 'logs',
        'Ec2' => 'ec2',
        'Ecr' => 'ecr',
        'Ecs' => 'ecs',
        'ElastiCache' => 'elasticache',
        'ElbV2' => 'elasticloadbalancing',
        'EventBridge' => 'events',
        'Iam' => 'iam',
        'Rds' => 'rds',
        'Route53' => 'route53',
        'S3' => 's3',
        'ServiceDiscovery' => 'servicediscovery',
        'Sns' => 'sns',
        'Sqs' => 'sqs',
        'WafV2' => 'wafv2',
    ];

    /**
     * The ARN service strings YOLO provisions — the values of the
     * resource-group catalogue above.
     *
     * @return array<int, string>
     */
    public static function managedServices(): array
    {
        return array_values(self::SERVICE_BY_RESOURCE_GROUP);
    }

    /**
     * Deploy ephemera the audit ignores: ECS task definitions (immutable
     * revisions pile up on every deploy and old ones can never be re-tagged) and
     * tasks (ephemeral runtime). They're versioned/runtime artefacts, not standing
     * infrastructure you'd leave behind, so auditing them is pure noise.
     *
     * @var array<string, array<int, string>>
     */
    private const array IGNORED_TYPES = [
        'ecs' => ['task-definition', 'task'],
    ];

    /**
     * The cluster suffix that is NOT an app: yolo-{env}-services hosts the
     * environment's shared service tasks (Typesense nodes), so deriving an app
     * named "services" from it would corrupt liveness — the claims registry
     * would wait forever for an app that can never publish. The name is
     * reserved at the manifest gate (Command::ensureNameNotReserved) so a real
     * app can never collide with it.
     */
    public const RESERVED_APP_NAME = 'services';

    /**
     * App names that have a live ECS cluster for this environment, derived from
     * cluster ARNs by the yolo-{env}-{app} naming convention. The bare yolo-{env}
     * cluster (none exists, but defensively), the env services cluster
     * (yolo-{env}-services — shared service tasks, not an app) and non-YOLO
     * clusters are ignored.
     *
     * @param  array<int, string>  $clusterArns
     * @return array<int, string>
     */
    public static function appsFromClusters(array $clusterArns, string $environment): array
    {
        $prefix = "yolo-$environment-";

        return collect($clusterArns)
            ->map(fn (string $arn): ?string => Arn::parse($arn)?->resourceId)
            ->filter(fn (?string $name): bool => $name !== null && str_starts_with($name, $prefix) && strlen($name) > strlen($prefix))
            ->map(fn (string $name): string => substr($name, strlen($prefix)))
            ->reject(fn (string $name): bool => $name === self::RESERVED_APP_NAME)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Classify every tagged resource against the live AWS inventory. Audit is an
     * ownership/inventory check, not a configuration check — it reads tags, the
     * ARN service and whether the owning app's cluster is live, and never
     * compares a resource's attributes against the manifest (that is `sync`'s
     * job). So there are just two statuses:
     *
     *   - `ok`         — accounted for. App-scope with a `yolo:app` pointing at a
     *                    live app, or env/account-scope shared infra YOLO owns.
     *   - `unexpected` — found in the environment's tag namespace but not
     *                    accounted for. The `reason` says why:
     *                      • REASON_NO_OWNER — no YOLO ownership marker at all
     *                        (`yolo:app`/`yolo:scope`); hand-rolled, or alpha-era
     *                        debris from before ownership tags.
     *                      • REASON_UNMANAGED_SERVICE — YOLO-owned, but of a
     *                        service YOLO no longer provisions (no `Resources/`
     *                        class), so a sync would never recreate it. The
     *                        DynamoDB sessions table left behind after DynamoDB
     *                        support was removed is the canonical case: still
     *                        tagged `yolo:app=<live app>`, so the ownership test
     *                        alone would read it `ok`, but it's dead weight.
     *                      • REASON_DEAD_APP — YOLO-owned, managed service, but
     *                        `yolo:app` points at an app whose cluster is gone.
     *
     * The reasons are evaluated most-specific first: no-owner before unmanaged
     * service before dead-app. A managed-service resource owned by a live app
     * (or env/account shared infra) is never flagged.
     *
     * @param  array<int, array{ResourceARN: string, Tags?: array<int, array{Key: string, Value: string}>}>  $taggedResources
     * @param  array<int, string>  $liveApps
     * @return array{resources: array<int, array<string, mixed>>, liveApps: array<int, string>, okCount: int, unexpectedCount: int}
     */
    public static function classify(array $taggedResources, array $liveApps): array
    {
        $managedServices = self::managedServices();

        $resources = collect($taggedResources)
            ->reject(fn (array $resource): bool => static::isIgnored(Arn::parse($resource['ResourceARN'])))
            ->map(function (array $resource) use ($liveApps, $managedServices): array {
                $tags = Aws::flattenTags($resource['Tags'] ?? []);
                $app = $tags[self::APP_TAG] ?? null;
                $scopeTag = $tags[self::SCOPE_TAG] ?? null;
                $parsed = Arn::parse($resource['ResourceARN']);

                $sharedScope = in_array($scopeTag, [self::SCOPE_ENV, self::SCOPE_ACCOUNT], true);
                $owned = $app !== null || $sharedScope;
                $managedService = $parsed instanceof Arn && in_array($parsed->service, $managedServices, true);

                [$status, $reason] = match (true) {
                    ! $owned => [self::STATUS_UNEXPECTED, self::REASON_NO_OWNER],
                    ! $managedService => [self::STATUS_UNEXPECTED, self::REASON_UNMANAGED_SERVICE],
                    $app !== null && ! in_array($app, $liveApps, true) => [self::STATUS_UNEXPECTED, self::REASON_DEAD_APP],
                    default => [self::STATUS_OK, null],
                };

                return [
                    'arn' => $resource['ResourceARN'],
                    'scope' => static::scope($tags),
                    'type' => static::type($parsed),
                    'name' => $tags[self::NAME_TAG] ?? $parsed->resourceId ?? $resource['ResourceARN'],
                    'app' => $app,
                    'status' => $status,
                    'reason' => $reason,
                ];
            });

        return [
            'resources' => $resources->values()->all(),
            'liveApps' => $liveApps,
            'okCount' => $resources->where('status', self::STATUS_OK)->count(),
            'unexpectedCount' => $resources->where('status', self::STATUS_UNEXPECTED)->count(),
        ];
    }

    /**
     * Ownership scope of a resource — account / env / app — read straight from
     * the `yolo:scope` tag that sync stamps on everything it creates (via
     * `ResolvesTags`, for every scope including the account-global OIDC provider).
     * A resource with no `yolo:scope` tag isn't YOLO-scoped — it's an unexpected,
     * unowned resource — so it's bucketed under `env` for display.
     *
     * @param  array<string, string>  $tags
     */
    public static function scope(array $tags): string
    {
        $scope = $tags[self::SCOPE_TAG] ?? null;

        return in_array($scope, [self::SCOPE_ACCOUNT, self::SCOPE_ENV, self::SCOPE_APP], true)
            ? $scope
            : self::SCOPE_ENV;
    }

    /**
     * A single composite sort key for the audit table: scope (account → env → app,
     * top to bottom), then status (unexpected before ok within a scope), then the
     * reason (so unexpected rows cluster by cause), then app and name.
     * Returned as one string so a single-closure `sortBy` orders the whole table —
     * the multi-closure `sortBy([...])` form silently ignores closure keys on
     * current illuminate/collections.
     *
     * @param  array<string, mixed>  $resource
     */
    public static function orderKey(array $resource): string
    {
        $scopeOrder = [self::SCOPE_ACCOUNT => 0, self::SCOPE_ENV => 1, self::SCOPE_APP => 2];
        $statusOrder = [self::STATUS_UNEXPECTED => 0, self::STATUS_OK => 1];

        return sprintf(
            '%d-%d-%s-%s-%s',
            $scopeOrder[$resource['scope']] ?? 9,
            $statusOrder[$resource['status']] ?? 9,
            $resource['reason'] ?? '',
            $resource['app'] ?? '',
            $resource['name'],
        );
    }

    /**
     * A readable resource type from the ARN — service, plus the resource-type
     * segment when there is one (e.g. ecs/service, elasticloadbalancing/targetgroup,
     * s3). Display only; no behaviour keys off it.
     */
    protected static function isIgnored(?Arn $arn): bool
    {
        return $arn instanceof Arn && in_array($arn->resourceType, self::IGNORED_TYPES[$arn->service] ?? [], true);
    }

    protected static function type(?Arn $arn): string
    {
        if (! $arn instanceof Arn) {
            return '?';
        }

        return $arn->resourceType === ''
            ? $arn->service
            : "{$arn->service}/{$arn->resourceType}";
    }
}
