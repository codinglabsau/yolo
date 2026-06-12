<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Services;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Aws\Ecs;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\EnvManifest;
use Symfony\Component\Yaml\Yaml;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Enums\ServiceState;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

/**
 * Decides whether an env-backed service should exist. A service runs while
 * two things are true:
 *
 *   1. The environment declares it — `services.{name}` in the env manifest,
 *      the environment's catalogue of what it runs. The entry stays put even
 *      when nothing is using the service; removing it is an operator act,
 *      never an inference.
 *   2. A running app is using it — every deploy/sync:app publishes the app's
 *      services (`apps/{app}.yml` in the env config bucket), and only apps
 *      with running tasks count (the audit liveness test), so a dead app
 *      can't keep a service alive.
 *
 * Declared ∧ in use → provision; anything else → teardown — EXCEPT that a
 * running app which hasn't deployed on this YOLO release yet hasn't told the
 * environment what it uses, so nothing is created or torn down until every
 * running app has. That makes the rollout bootstrap-safe: day one nobody has
 * republished, nothing can tear down.
 *
 * Usage and liveness are read once per process (both sync passes see the
 * same world); on a greenfield plan pass the env config bucket doesn't exist
 * yet, which reads as nothing published — never an error.
 */
class Lifecycle
{
    /** @var array<string, array<int, string>>|null app name => services it uses */
    protected static ?array $published = null;

    /** @var array<int, string>|null */
    protected static ?array $liveApps = null;

    public static function state(Service $service): ServiceState
    {
        $declared = EnvManifest::has('services.' . $service->value);
        $using = static::liveAppsUsing($service);

        // Reachable only by editing the env manifest outside environment:manifest:push
        // (push refuses to remove a service apps still use) — surface the
        // contradiction instead of tearing infrastructure out from under the apps.
        if (! $declared && $using !== []) {
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

        if ($declared && $using !== []) {
            return ServiceState::Provision;
        }

        return static::unpublishedLiveApps() === [] ? ServiceState::Teardown : ServiceState::Retain;
    }

    /**
     * The running apps whose published services include this one. A dead app
     * can't keep a service alive — only apps with running tasks count.
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
     * Running apps that haven't published their services yet — apps deployed
     * by a YOLO that predates the registry. The environment doesn't know what
     * they use, so they block teardown (and env-manifest removal) until their
     * next deploy/sync:app.
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
     * Forget the memoised registry — tests bind fresh AWS mocks per case.
     */
    public static function reset(): void
    {
        static::$published = null;
        static::$liveApps = null;
    }

    /**
     * Every published services file under apps/ in the env config bucket,
     * parsed to app name => the services it uses. A missing bucket (a
     * greenfield plan pass) reads as nothing published; a file we can't read
     * is a hard error — unreadable is not the same as "uses nothing".
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

        // PublishAppManifestStep dumps an empty services list as a YAML map,
        // so `services: {}` parses back to [] — both shapes are a valid list.
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
