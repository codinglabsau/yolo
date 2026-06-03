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

    private const NAME_TAG = 'Name';

    public const STATUS_OK = 'ok';

    public const STATUS_DRIFT = 'drift';

    public const STATUS_ROGUE = 'rogue';

    public const STATUS_ORPHAN = 'orphan';

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
        'Sns' => 'sns',
        'Sqs' => 'sqs',
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
    private const IGNORED_TYPES = [
        'ecs' => ['task-definition', 'task'],
    ];

    /**
     * App names that have a live ECS cluster for this environment, derived from
     * cluster ARNs by the yolo-{env}-{app} naming convention. The bare yolo-{env}
     * cluster (none exists, but defensively) and non-YOLO clusters are ignored.
     *
     * @param  array<int, string>  $clusterArns
     * @return array<int, string>
     */
    public static function appsFromClusters(array $clusterArns, string $environment): array
    {
        $prefix = "yolo-$environment-";

        return collect($clusterArns)
            ->map(fn (string $arn) => Arn::parse($arn)?->resourceId)
            ->filter(fn (?string $name) => $name !== null && str_starts_with($name, $prefix) && strlen($name) > strlen($prefix))
            ->map(fn (string $name) => substr($name, strlen($prefix)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Classify every tagged resource against the live AWS inventory:
     *   - `ok`     — declared and accounted for. Either app-scope with a
     *                `yolo:app` pointing at a live app, or env/account-scope
     *                with a `yolo:scope` tag (shared infra YOLO owns by design).
     *   - `drift`  — `yolo:app` points at an app whose cluster is gone.
     *   - `orphan` — carries a YOLO ownership marker but is of an AWS service
     *                YOLO no longer provisions (no `Resources/` class for it),
     *                so sync would never (re)create it. The DynamoDB sessions
     *                table left behind after DynamoDB support was removed is
     *                the canonical case: still tagged `yolo:app=<live app>`, so
     *                it reads as `ok` under the ownership test alone, but YOLO
     *                has no DynamoDB resource any more — it's dead weight to
     *                tear down. Takes precedence over ok/drift, since "YOLO
     *                doesn't manage this kind of resource" is the more
     *                actionable verdict regardless of whether the owning app is
     *                still live.
     *   - `rogue`  — has `yolo:environment` (so the audit found it) but no
     *                YOLO ownership marker (`yolo:app`, `yolo:scope=env`,
     *                `yolo:scope=account`). Either alpha-era debris from
     *                before the scope-tag rollout, or a hand-rolled resource
     *                that sneaked into the env namespace.
     *
     * Drift is only ever raised from an explicit yolo:app pointing at a dead app,
     * and orphan only from an ownership marker plus an unmanaged service, so
     * shared infrastructure of a managed service is never false-flagged.
     *
     * @param  array<int, array{ResourceARN: string, Tags?: array<int, array{Key: string, Value: string}>}>  $taggedResources
     * @param  array<int, string>  $liveApps
     * @return array{resources: array<int, array<string, mixed>>, liveApps: array<int, string>, okCount: int, driftCount: int, orphanCount: int, rogueCount: int}
     */
    public static function classify(array $taggedResources, array $liveApps): array
    {
        $managedServices = self::managedServices();

        $resources = collect($taggedResources)
            ->reject(fn (array $resource) => static::isIgnored(Arn::parse($resource['ResourceARN'])))
            ->map(function (array $resource) use ($liveApps, $managedServices) {
                $tags = Aws::flattenTags($resource['Tags'] ?? []);
                $app = $tags[self::APP_TAG] ?? null;
                $scopeTag = $tags[self::SCOPE_TAG] ?? null;
                $parsed = Arn::parse($resource['ResourceARN']);

                $sharedScope = in_array($scopeTag, [self::SCOPE_ENV, self::SCOPE_ACCOUNT], true);
                $owned = $app !== null || $sharedScope;
                $managedService = $parsed !== null && in_array($parsed->service, $managedServices, true);

                $status = match (true) {
                    $owned && ! $managedService => self::STATUS_ORPHAN,
                    $app !== null && in_array($app, $liveApps, true) => self::STATUS_OK,
                    $app !== null => self::STATUS_DRIFT,
                    $sharedScope => self::STATUS_OK,
                    default => self::STATUS_ROGUE,
                };

                return [
                    'arn' => $resource['ResourceARN'],
                    'scope' => static::scope($parsed, $tags),
                    'type' => static::type($parsed),
                    'name' => $tags[self::NAME_TAG] ?? $parsed?->resourceId ?? $resource['ResourceARN'],
                    'app' => $app,
                    'status' => $status,
                ];
            });

        return [
            'resources' => $resources->values()->all(),
            'liveApps' => $liveApps,
            'okCount' => $resources->where('status', self::STATUS_OK)->count(),
            'driftCount' => $resources->where('status', self::STATUS_DRIFT)->count(),
            'orphanCount' => $resources->where('status', self::STATUS_ORPHAN)->count(),
            'rogueCount' => $resources->where('status', self::STATUS_ROGUE)->count(),
        ];
    }

    /**
     * Ownership scope of a resource — account / env / app — mirroring the
     * `Enums\Scope` a resource declares in code. Reads the explicit `yolo:scope`
     * tag stamped by sync; falls back to inference for resources synced before
     * the tag rollout (a `yolo:app` tag means app-scope; the GitHub OIDC provider
     * is account-scope; everything else yolo-tagged is env-shared infra).
     *
     * @param  array<string, string>  $tags
     */
    public static function scope(?Arn $arn, array $tags): string
    {
        $scope = $tags[self::SCOPE_TAG] ?? null;

        return match (true) {
            in_array($scope, [self::SCOPE_ACCOUNT, self::SCOPE_ENV, self::SCOPE_APP], true) => $scope,
            isset($tags[self::APP_TAG]) => self::SCOPE_APP,
            static::isAccountGlobal($arn) => self::SCOPE_ACCOUNT,
            default => self::SCOPE_ENV,
        };
    }

    /**
     * Account-global resources carry no env/app scoping. Today the only one YOLO
     * provisions is the GitHub OIDC identity provider (one per account, shared by
     * every environment).
     */
    protected static function isAccountGlobal(?Arn $arn): bool
    {
        return $arn !== null && $arn->service === 'iam' && $arn->resourceType === 'oidc-provider';
    }

    /**
     * A single composite sort key for the audit table: scope (account → env → app,
     * top to bottom), then status (cleanup first within a scope — drift, then
     * orphan, then rogue, then ok), then app and name.
     * Returned as one string so a single-closure `sortBy` orders the whole table —
     * the multi-closure `sortBy([...])` form silently ignores closure keys on
     * current illuminate/collections.
     *
     * @param  array<string, mixed>  $resource
     */
    public static function orderKey(array $resource): string
    {
        $scopeOrder = [self::SCOPE_ACCOUNT => 0, self::SCOPE_ENV => 1, self::SCOPE_APP => 2];
        $statusOrder = [self::STATUS_DRIFT => 0, self::STATUS_ORPHAN => 1, self::STATUS_ROGUE => 2, self::STATUS_OK => 3];

        return sprintf(
            '%d-%d-%s-%s',
            $scopeOrder[$resource['scope']] ?? 9,
            $statusOrder[$resource['status']] ?? 9,
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
        return $arn !== null && in_array($arn->resourceType, self::IGNORED_TYPES[$arn->service] ?? [], true);
    }

    protected static function type(?Arn $arn): string
    {
        if ($arn === null) {
            return '?';
        }

        return $arn->resourceType === ''
            ? $arn->service
            : "{$arn->service}/{$arn->resourceType}";
    }
}
