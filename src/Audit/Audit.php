<?php

namespace Codinglabs\Yolo\Audit;

use Codinglabs\Yolo\Arn;
use Codinglabs\Yolo\Aws;

/**
 * Pure classification for `yolo audit` — no AWS calls; the command does the I/O
 * and feeds the data in, so this stays unit-testable in isolation.
 */
class Audit
{
    public const APP_TAG = 'yolo:app';

    public const SCOPE_TAG = 'yolo:scope';

    private const string NAME_TAG = 'Name';

    public const STATUS_OK = 'ok';

    public const STATUS_UNEXPECTED = 'unexpected';

    /**
     * Audit inspects tags, the ARN service and cluster liveness only — never a
     * resource's configuration against the manifest (that's `sync`'s job), so
     * every reason here is an ownership/inventory fact, not a config concern.
     */
    public const REASON_DEAD_APP = 'app cluster gone';

    public const REASON_UNMANAGED_SERVICE = 'service no longer provisioned';

    public const REASON_NO_OWNER = 'no ownership tag';

    public const SCOPE_ACCOUNT = 'account';

    public const SCOPE_ENV = 'env';

    public const SCOPE_APP = 'app';

    /**
     * Keys mirror the `src/Resources/*` directories one-for-one (test-enforced),
     * so dropping a service directory automatically surfaces its leftover
     * resources as unmanaged, and adding one fails until catalogued here — a
     * newly-supported service is never false-flagged.
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
     * @return array<int, string>
     */
    public static function managedServices(): array
    {
        return array_values(self::SERVICE_BY_RESOURCE_GROUP);
    }

    /**
     * Task definitions pile up as immutable revisions that can never be re-tagged
     * and tasks are ephemeral runtime — auditing either is pure noise.
     *
     * @var array<string, array<int, string>>
     */
    private const array IGNORED_TYPES = [
        'ecs' => ['task-definition', 'task'],
    ];

    /**
     * yolo-{env}-services hosts the environment's shared service tasks, not an
     * app; deriving an app from it would corrupt liveness. Reserved at the
     * manifest gate (Command::ensureNameNotReserved) so a real app can't collide.
     */
    public const RESERVED_APP_NAME = 'services';

    /**
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
     * Reasons are evaluated most-specific first. The unmanaged-service check must
     * precede the ownership pass: a leftover of a service YOLO no longer
     * provisions can still carry `yolo:app=<live app>` and would otherwise read
     * `ok` even though no sync would ever recreate it.
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
     * A resource with no `yolo:scope` tag is unowned; it's bucketed under `env`
     * for display only.
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
     * One composite string so a single-closure `sortBy` orders the whole table —
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
