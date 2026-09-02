<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

use Codinglabs\Yolo\Aws\S3;
use Illuminate\Support\Arr;
use Symfony\Component\Yaml\Yaml;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Enums\Service;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

/**
 * Env-shared desired state, one yolo-environment-{environment}.yml per environment in the env
 * config bucket: yolo.yml declares what one app needs, this declares what the environment
 * provides. The environment is in the filename so a pulled copy can never be pushed at the
 * wrong environment. Every sync pulls it fresh and reconciles toward it; a manifest carrying
 * keys from a newer release hard-fails older binaries.
 */
class EnvManifest
{
    public static function filename(): string
    {
        return sprintf('yolo-environment-%s.yml', Helpers::environment());
    }

    /**
     * `services` is the extension point: each env-backed service contributes its own key plus
     * its declared offer keys, so adding a service never edits this class.
     *
     * @return array<int, string>
     */
    protected static function allowedKeys(): array
    {
        $serviceKeys = [];

        foreach (Service::cases() as $service) {
            $definition = $service->definition();

            if (! $definition->envBacked()) {
                continue;
            }

            $serviceKeys[] = $service->envManifestKey();

            foreach ($definition->offerKeys() as $key) {
                $serviceKeys[] = $service->envManifestKey() . '.' . $key;
            }
        }

        return ['domain', 'services', 'budget', 'budget.amount', 'budget.strategy', 'peering', ...$serviceKeys];
    }

    /**
     * Env-shared because peering is VPC-to-VPC (typically a database mid-migration). A
     * non-VPC-id entry hard-fails rather than silently provisioning nothing.
     *
     * @return array<int, string>
     */
    public static function peering(): array
    {
        $peering = static::get('peering', []);

        // A bare `peering:` with every entry removed parses as null — nothing declared, not a shape error.
        if ($peering === null) {
            return [];
        }

        if (! is_array($peering) || ! array_is_list($peering)) {
            throw new IntegrityCheckException('The env manifest `peering` key must be a list of VPC ids (e.g. [vpc-0abc123]).');
        }

        foreach ($peering as $vpcId) {
            if (! is_string($vpcId) || preg_match('/^vpc-[0-9a-f]+$/', $vpcId) !== 1) {
                throw new IntegrityCheckException(sprintf(
                    'Invalid `peering` entry "%s" — each entry must be a VPC id (vpc-…).',
                    is_scalar($vpcId) ? (string) $vpcId : gettype($vpcId),
                ));
            }
        }

        return $peering;
    }

    /** @var array<string, mixed>|null */
    protected static ?array $loaded = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        return Arr::get(static::current(), $key, $default);
    }

    public static function has(string $key): bool
    {
        return Arr::has(static::current(), $key);
    }

    /**
     * A missing object or bucket (greenfield plan pass) reads as an empty manifest, so
     * consumers skip cleanly before the first sync seeds the file. Only the genuine not-found
     * set reads as absence — AccessDenied or a transient fault must fail the sync.
     *
     * @return array<string, mixed>
     */
    public static function current(): array
    {
        if (static::$loaded !== null) {
            return static::$loaded;
        }

        try {
            $body = (string) Aws::s3()->getObject([
                'Bucket' => Paths::s3EnvConfigBucket(),
                'Key' => static::filename(),
            ])['Body'];
        } catch (S3Exception $e) {
            if (S3::isNotFound($e)) {
                return static::$loaded = [];
            }

            throw $e;
        }

        try {
            return static::$loaded = static::parse($body);
        } catch (IntegrityCheckException $e) {
            // The bucket copy outlives any one release — an unknown key usually means a
            // newer YOLO pushed it, not that the file is broken.
            throw new IntegrityCheckException($e->getMessage() . ' The bucket manifest may have been written by a newer YOLO release — update codinglabsau/yolo and retry.', $e->getCode(), $e);
        }
    }

    /**
     * Shared with environment:manifest:push so a misshapen manifest can't reach the bucket.
     *
     * @return array<string, mixed>
     */
    public static function parse(string $contents): array
    {
        $manifest = Yaml::parse($contents) ?? [];

        if (! is_array($manifest)) {
            throw new IntegrityCheckException(sprintf('%s must be a YAML map.', static::filename()));
        }

        // The app manifest's `services: [ivs]` list flattens to the bare (allowed) `services`
        // path and would validate clean, then provision nothing — same key, opposite shapes.
        if (isset($manifest['services']) && is_array($manifest['services']) && array_is_list($manifest['services']) && $manifest['services'] !== []) {
            throw new IntegrityCheckException(sprintf(
                'services in %s must be a map of service => config (services: { ivs: {} }) — the list form belongs to the app manifest (yolo.yml).',
                static::filename(),
            ));
        }

        $unknown = static::unknownKeys($manifest);

        if ($unknown !== []) {
            throw new IntegrityCheckException(sprintf(
                'Unrecognised %s in %s: %s.',
                count($unknown) === 1 ? 'key' : 'keys',
                static::filename(),
                implode(', ', $unknown),
            ));
        }

        // The allow-list catches unknown keys; this catches a misshapen offer before it
        // becomes the environment's declared truth.
        foreach (Service::cases() as $service) {
            $path = $service->envManifestKey();

            if ($service->definition()->envBacked() && Arr::has($manifest, $path)) {
                $service->definition()->validateOffer(Arr::get($manifest, $path), static::filename());
            }
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<int, string>
     */
    public static function unknownKeys(array $manifest): array
    {
        return array_values(array_filter(
            Manifest::flattenKeys($manifest),
            fn (string $path): bool => ! static::keyAllowed($path),
        ));
    }

    /** Distinct from current(), which reads absence as empty — the seed step must create the file exactly once. */
    public static function remoteExists(): bool
    {
        try {
            Aws::s3()->headObject([
                'Bucket' => Paths::s3EnvConfigBucket(),
                'Key' => static::filename(),
            ]);

            return true;
        } catch (S3Exception $e) {
            // A denied or failed read must not report the manifest missing, or the seed
            // step would overwrite the operator's file with the stub.
            if (S3::isNotFound($e)) {
                return false;
            }

            throw $e;
        }
    }

    /** Seed-only, so operator edits always stick. */
    public static function seedContents(): string
    {
        return (string) file_get_contents(Paths::stubs('yolo-environment.yml.stub'));
    }

    /** Gitignored via yolo-environment-*.yml, which never matches yolo.yml. */
    public static function localPath(): string
    {
        return Paths::base(static::filename());
    }

    /** Tests bind fresh S3 mocks per case. */
    public static function reset(): void
    {
        static::$loaded = null;
    }

    protected static function keyAllowed(string $path): bool
    {
        foreach (static::allowedKeys() as $allowed) {
            if ($allowed === $path) {
                return true;
            }

            if (str_ends_with($allowed, '.*') && str_starts_with($path . '.', substr($allowed, 0, -1))) {
                return true;
            }
        }

        return false;
    }
}
