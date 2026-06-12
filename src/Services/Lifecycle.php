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
 * The two-key lifecycle gate for env-backed services. A service exists in an
 * environment when both keys turn:
 *
 *   1. OFFERED — the env manifest declares `services.{name}`. The entry is the
 *      environment's catalogue: consent, shape, and the memory of what the env
 *      runs. It deliberately survives zero consumers.
 *   2. CLAIMED — at least one live app (an ECS cluster with running tasks, the
 *      audit liveness test) has published `apps/{app}.yml` listing the service.
 *
 * Offered ∧ claimed → provision; anything else → teardown — EXCEPT that a live
 * app with no published claim file makes the claim set unknowable (unknown
 * state ≠ no claims), which blocks teardown until every live app's next
 * deploy/sync:app populates the registry. That makes the rollout
 * bootstrap-safe: day one nobody has published, nothing can tear down.
 *
 * Claims and liveness are read once per process (both sync passes see the
 * same world); on a greenfield plan pass the env config bucket doesn't exist
 * yet, which reads as an empty registry — never an error.
 */
class Lifecycle
{
    /** @var array<string, array<int, string>>|null app name => claimed service names */
    protected static ?array $claims = null;

    /** @var array<int, string>|null */
    protected static ?array $liveApps = null;

    public static function state(Service $service): ServiceState
    {
        $offered = EnvManifest::has('services.' . $service->value);
        $claimants = static::activeClaimants($service);

        // Reachable only by editing the env manifest outside environment:manifest:push
        // (push refuses to remove an offer with live claimants) — surface the
        // contradiction instead of tearing infrastructure out from under the apps.
        if (! $offered && $claimants !== []) {
            throw new IntegrityCheckException(sprintf(
                'Live apps still claim the %s service (%s) but the environment manifest no longer offers services.%s. '
                . 'Re-add the offer via `yolo environment:manifest:pull/push`, or drop the claim from each app\'s yolo.yml and deploy (or `yolo sync:app`) it first.',
                $service->value,
                implode(', ', $claimants),
                $service->value,
            ));
        }

        if ($offered && $claimants !== []) {
            return ServiceState::Provision;
        }

        return static::unpublishedLiveApps() === [] ? ServiceState::Teardown : ServiceState::Retain;
    }

    /**
     * The live apps whose published claim file lists this service. A dead
     * app's stale claim can't hold an offer hostage — claims only count while
     * the claiming app's cluster is running tasks.
     *
     * @return array<int, string>
     */
    public static function activeClaimants(Service $service): array
    {
        $claimants = array_values(array_filter(
            static::liveApps(),
            fn (string $app): bool => in_array($service->value, static::claims()[$app] ?? [], true),
        ));

        sort($claimants);

        return $claimants;
    }

    /**
     * Live apps with no published claim file — apps deployed by a YOLO that
     * predates the claims registry. Their claims are unknowable, so they block
     * teardown (and offer removal) until their next deploy/sync:app publishes.
     *
     * @return array<int, string>
     */
    public static function unpublishedLiveApps(): array
    {
        $unpublished = array_values(array_filter(
            static::liveApps(),
            fn (string $app): bool => ! array_key_exists($app, static::claims()),
        ));

        sort($unpublished);

        return $unpublished;
    }

    /**
     * Forget the memoised registry — tests bind fresh AWS mocks per case.
     */
    public static function reset(): void
    {
        static::$claims = null;
        static::$liveApps = null;
    }

    /**
     * Every published claim file under apps/ in the env config bucket, parsed
     * to app name => claimed services. A missing bucket (greenfield plan pass)
     * reads as an empty registry; a malformed claim file is a hard error — a
     * registry we can't read is not a registry reporting "no claims".
     *
     * @return array<string, array<int, string>>
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

                    [$app, $services] = static::parseClaim((string) $object['Key']);

                    $claims[$app] = $services;
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
     * @return array{0: string, 1: array<int, string>}
     */
    protected static function parseClaim(string $key): array
    {
        $claim = Yaml::parse((string) Aws::s3()->getObject([
            'Bucket' => Paths::s3EnvConfigBucket(),
            'Key' => $key,
        ])['Body']);

        $name = is_array($claim) ? ($claim['name'] ?? null) : null;
        $services = is_array($claim) ? ($claim['services'] ?? null) : null;

        // PublishAppManifestStep dumps an empty services list as a YAML map,
        // so `services: {}` parses back to [] — both shapes are a valid list.
        if (! is_string($name) || $name === '' || ! is_array($services) || ! array_is_list($services)) {
            throw new IntegrityCheckException(sprintf(
                'Malformed claim file s3://%s/%s — expected `name` and a `services` list. Deploy or `yolo sync:app` the app to republish it.',
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
