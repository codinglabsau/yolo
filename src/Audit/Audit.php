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

    private const NAME_TAG = 'Name';

    public const STATUS_LIVE = 'live';

    public const STATUS_DRIFT = 'drift';

    public const STATUS_UNATTRIBUTED = 'unattributed';

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
     * Classify every tagged resource as belonging to a live app, drift (tagged
     * for an app that's no longer live), or unattributed (no yolo:app tag — shared
     * infrastructure, or a resource a sync hasn't stamped yet).
     *
     * Drift is only ever raised from an explicit yolo:app pointing at a dead app,
     * so shared infrastructure (which carries no yolo:app) is never false-flagged.
     *
     * @param  array<int, array{ResourceARN: string, Tags?: array<int, array{Key: string, Value: string}>}>  $taggedResources
     * @param  array<int, string>  $liveApps
     * @return array{resources: array<int, array<string, mixed>>, liveApps: array<int, string>, liveCount: int, driftCount: int, unattributedCount: int}
     */
    public static function classify(array $taggedResources, array $liveApps): array
    {
        $resources = collect($taggedResources)
            ->map(function (array $resource) use ($liveApps) {
                $tags = Aws::flattenTags($resource['Tags'] ?? []);
                $app = $tags[self::APP_TAG] ?? null;
                $parsed = Arn::parse($resource['ResourceARN']);

                $status = match (true) {
                    $app === null => self::STATUS_UNATTRIBUTED,
                    in_array($app, $liveApps, true) => self::STATUS_LIVE,
                    default => self::STATUS_DRIFT,
                };

                return [
                    'arn' => $resource['ResourceARN'],
                    'type' => static::type($parsed),
                    'name' => $tags[self::NAME_TAG] ?? $parsed?->resourceId ?? $resource['ResourceARN'],
                    'app' => $app,
                    'status' => $status,
                ];
            });

        return [
            'resources' => $resources->values()->all(),
            'liveApps' => $liveApps,
            'liveCount' => $resources->where('status', self::STATUS_LIVE)->count(),
            'driftCount' => $resources->where('status', self::STATUS_DRIFT)->count(),
            'unattributedCount' => $resources->where('status', self::STATUS_UNATTRIBUTED)->count(),
        ];
    }

    /**
     * A readable resource type from the ARN — service, plus the resource-type
     * segment when there is one (e.g. ecs/service, elasticloadbalancing/targetgroup,
     * s3). Display only; no behaviour keys off it.
     */
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
