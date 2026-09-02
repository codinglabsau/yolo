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
    /** @var array<string, array<int, string>>|null app name => services it uses */
    protected static ?array $published = null;

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
                . 'Put the entry back with `yolo environment:manifest:pull/push`, or remove %s from each app\'s yolo.yml and deploy (or `yolo sync:app`) it first.',
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
     * teardown (and env-manifest removal) until its next deploy/sync:app.
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

    /** Tests bind fresh AWS mocks per case. */
    public static function reset(): void
    {
        static::$published = null;
        static::$liveApps = null;
    }

    /**
     * A missing bucket (greenfield plan pass) reads as nothing published; an
     * unreadable file is a hard error — unreadable is not "uses nothing".
     *
     * @return array<string, array<int, string>>
     */
    protected static function published(): array
    {
        if (static::$published !== null) {
            return static::$published;
        }

        $published = [];
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

                    [$app, $services] = static::parseServicesFile((string) $object['Key']);

                    $published[$app] = $services;
                }

                $token = ($result['IsTruncated'] ?? false) ? ($result['NextContinuationToken'] ?? null) : null;
            } while ($token !== null);
        } catch (S3Exception $e) {
            if (S3::isNotFound($e)) {
                return static::$published = [];
            }

            throw $e;
        }

        return static::$published = $published;
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    protected static function parseServicesFile(string $key): array
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
                'Could not read s3://%s/%s — expected the app\'s name and its services list. A fresh `yolo deploy` or `yolo sync:app` from that app rewrites it.',
                Paths::s3EnvConfigBucket(),
                $key,
            ));
        }

        return [$name, array_map(strval(...), $services)];
    }

    /**
     * @return array<int, string>
     */
    protected static function liveApps(): array
    {
        return static::$liveApps ??= Ecs::liveApps(Helpers::environment());
    }
}
