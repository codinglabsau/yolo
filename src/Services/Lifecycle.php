<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Services;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Destroying;
use Codinglabs\Yolo\EnvManifest;
use Symfony\Component\Yaml\Yaml;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;
use Codinglabs\Yolo\Steps\Sync\App\PublishAppManifestStep;

/**
 * An env-backed service exists iff the env manifest declares it. Declaration is
 * the operator's deliberate, billed decision — never inferred from a consumer
 * being up or down; consumption only informs the idle warning. Liveness reads
 * the services each app publishes (`apps/{app}.yml`), counting only apps with
 * running tasks. Both reads are memoised so the plan and apply passes see the
 * same world, and a greenfield env (no config bucket) reads as nothing published.
 */
class Lifecycle
{
    /** @var array<string, array{services: array<int, string>, bucket: string|null}>|null app name => its published claim */
    protected static ?array $claims = null;

    /** @var array<int, string>|null */
    protected static ?array $liveApps = null;

    public static function state(Service $service): ServiceState
    {
        if (Destroying::active()) {
            return ServiceState::Teardown;
        }

        if (EnvManifest::has($service->envManifestKey())) {
            return ServiceState::Provision;
        }

        // A running app still using an undeclared service means the manifest was
        // edited outside environment:manifest:push (which refuses that removal) —
        // surface the contradiction rather than tear infrastructure out from under it.
        $using = static::liveAppsUsing($service);

        if ($using !== []) {
            throw new IntegrityCheckException(sprintf(
                '%s %s still using the %s service, but the environment manifest no longer declares services.%s. '
                . 'Put the entry back with `yolo environment:manifest:pull/push`, or remove %s from each app\'s yolo.yml and `yolo sync:app` it first.',
                implode(', ', $using),
                count($using) === 1 ? 'is' : 'are',
                $service->value,
                $service->value,
                $service->value,
            ));
        }

        return ServiceState::Teardown;
    }

    /**
     * A dead app can't keep a service alive — only apps with running tasks count.
     *
     * @return array<int, string>
     */
    public static function liveAppsUsing(Service $service): array
    {
        $using = array_values(array_filter(
            static::liveApps(),
            fn (string $app): bool => in_array($service->value, static::published()[$app] ?? [], true),
        ));

        sort($using);

        return $using;
    }

    /**
     * Published a claim file or has running tasks. destroy:environment refuses
     * while any remain so env-shared resources never go out from under a live app.
     *
     * @return array<int, string>
     */
    public static function claimingApps(): array
    {
        $apps = array_values(array_unique([
            ...array_keys(static::published()),
            ...static::liveApps(),
        ]));

        sort($apps);

        return $apps;
    }

    /**
     * The environment doesn't know what an unpublished app uses, so it blocks
     * teardown (and env-manifest removal) until its next sync:app.
     *
     * @return array<int, string>
     */
    public static function unpublishedLiveApps(): array
    {
        $unpublished = array_values(array_filter(
            static::liveApps(),
            fn (string $app): bool => ! array_key_exists($app, static::published()),
        ));

        sort($unpublished);

        return $unpublished;
    }

    /**
     * Every bring-your-own data bucket named by a published app, for the env-wide
     * data tiers (safe to grant from because only sync:app writes a claim — see
     * {@see PublishAppManifestStep}). The YOLO-named
     * buckets sit inside the `-data` wildcard, and a `yolo-` prefixed name is dropped
     * here so a claim can never widen a grant onto YOLO's own infrastructure buckets.
     * A name that isn't a valid bucket name at all (an IAM wildcard, say) is a
     * corrupted claim and fails loudly. Sorted so the policy document is stable.
     *
     * @return array<int, string>
     */
    public static function publishedBuckets(): array
    {
        $buckets = [];

        foreach (static::claims() as $app => $claim) {
            $bucket = $claim['bucket'];

            if ($bucket === null || str_starts_with($bucket, 'yolo-')) {
                continue;
            }

            if (! S3::isValidBucketName($bucket)) {
                throw new IntegrityCheckException(sprintf(
                    'The claim for %s names "%s" as its bucket, which is not a valid bucket name — refusing to grant on it. Fix `bucket:` in that app\'s yolo.yml and `yolo sync:app` it.',
                    $app,
                    $bucket,
                ));
            }

            $buckets[] = $bucket;
        }

        $buckets = array_values(array_unique($buckets));

        sort($buckets);

        return $buckets;
    }

    /** Tests bind fresh AWS mocks per case. */
    public static function reset(): void
    {
        static::$claims = null;
        static::$liveApps = null;
    }

    /**
     * @return array<string, array<int, string>> app name => services it uses
     */
    protected static function published(): array
    {
        return array_map(fn (array $claim): array => $claim['services'], static::claims());
    }

    /**
     * A missing bucket (greenfield plan pass) reads as nothing published; an
     * unreadable file is a hard error — unreadable is not "uses nothing".
     *
     * @return array<string, array{services: array<int, string>, bucket: string|null}>
     */
    protected static function claims(): array
    {
        if (static::$claims !== null) {
            return static::$claims;
        }

        $claims = [];
        $token = null;

        try {
            do {
                $result = Aws::s3()->listObjectsV2(array_filter([
                    'Bucket' => Paths::s3EnvConfigBucket(),
                    'Prefix' => 'apps/',
                    'ContinuationToken' => $token,
                ]));

                foreach ($result['Contents'] ?? [] as $object) {
                    if (! str_ends_with((string) $object['Key'], '.yml')) {
                        continue;
                    }

                    [$app, $claim] = static::parseClaimFile((string) $object['Key']);

                    $claims[$app] = $claim;
                }

                $token = ($result['IsTruncated'] ?? false) ? ($result['NextContinuationToken'] ?? null) : null;
            } while ($token !== null);
        } catch (S3Exception $e) {
            if (S3::isNotFound($e)) {
                return static::$claims = [];
            }

            throw $e;
        }

        return static::$claims = $claims;
    }

    /**
     * `bucket` is the app's per-env manifest value: `true` (YOLO-named, covered by
     * the namespace wildcard) reads as no BYO bucket; a string is one.
     *
     * @return array{0: string, 1: array{services: array<int, string>, bucket: string|null}}
     */
    protected static function parseClaimFile(string $key): array
    {
        $file = Yaml::parse((string) Aws::s3()->getObject([
            'Bucket' => Paths::s3EnvConfigBucket(),
            'Key' => $key,
        ])['Body']);

        $name = is_array($file) ? ($file['name'] ?? null) : null;
        $services = is_array($file) ? ($file['services'] ?? null) : null;

        // An empty services list dumps as `services: {}`, which parses back to [] — still a valid list.
        if (! is_string($name) || $name === '' || ! is_array($services) || ! array_is_list($services)) {
            throw new IntegrityCheckException(sprintf(
                'Could not read s3://%s/%s — expected the app\'s name and its services list. A fresh `yolo sync:app` from that app rewrites it.',
                Paths::s3EnvConfigBucket(),
                $key,
            ));
        }

        $bucket = $file['bucket'] ?? null;

        return [$name, [
            'services' => array_map(strval(...), $services),
            'bucket' => is_string($bucket) && $bucket !== '' ? $bucket : null,
        ]];
    }

    /**
     * @return array<int, string>
     */
    protected static function liveApps(): array
    {
        return static::$liveApps ??= Ecs::liveApps(Helpers::environment());
    }
}
