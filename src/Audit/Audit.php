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

    public const SCOPE_ACCOUNT = 'account';

    public const SCOPE_ENV = 'env';

    public const SCOPE_APP = 'app';

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
     *   - `rogue`  — has `yolo:environment` (so the audit found it) but no
     *                YOLO ownership marker (`yolo:app`, `yolo:scope=env`,
     *                `yolo:scope=account`). Either alpha-era debris from
     *                before the scope-tag rollout, or a hand-rolled resource
     *                that sneaked into the env namespace.
     *
     * Drift is only ever raised from an explicit yolo:app pointing at a dead app,
     * so shared infrastructure is never false-flagged.
     *
     * @param  array<int, array{ResourceARN: string, Tags?: array<int, array{Key: string, Value: string}>}>  $taggedResources
     * @param  array<int, string>  $liveApps
     * @return array{resources: array<int, array<string, mixed>>, liveApps: array<int, string>, okCount: int, driftCount: int, rogueCount: int}
     */
    public static function classify(array $taggedResources, array $liveApps): array
    {
        $resources = collect($taggedResources)
            ->reject(fn (array $resource) => static::isIgnored(Arn::parse($resource['ResourceARN'])))
            ->map(function (array $resource) use ($liveApps) {
                $tags = Aws::flattenTags($resource['Tags'] ?? []);
                $app = $tags[self::APP_TAG] ?? null;
                $scopeTag = $tags[self::SCOPE_TAG] ?? null;
                $parsed = Arn::parse($resource['ResourceARN']);

                $sharedScope = in_array($scopeTag, [self::SCOPE_ENV, self::SCOPE_ACCOUNT], true);

                $status = match (true) {
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
     * top to bottom), then status (drift first within a scope), then app and name.
     * Returned as one string so a single-closure `sortBy` orders the whole table —
     * the multi-closure `sortBy([...])` form silently ignores closure keys on
     * current illuminate/collections.
     *
     * @param  array<string, mixed>  $resource
     */
    public static function orderKey(array $resource): string
    {
        $scopeOrder = [self::SCOPE_ACCOUNT => 0, self::SCOPE_ENV => 1, self::SCOPE_APP => 2];
        $statusOrder = [self::STATUS_DRIFT => 0, self::STATUS_ROGUE => 1, self::STATUS_OK => 2];

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
