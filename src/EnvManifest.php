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
 * The environment manifest — env-shared desired state, one
 * yolo-environment-{environment}.yml per environment, stored in the env config
 * bucket rather than any app's repo. The app manifest (yolo.yml) declares what
 * one app needs; this declares what the environment provides (shared-service
 * ingress domain, env services and their sizing). The environment is in the
 * filename, so a pulled copy can never be pushed at the wrong environment.
 *
 * Every sync pulls it fresh from S3 and reconciles toward it, so syncs from
 * any app repo converge on the environment's own declared truth — within the
 * schema each release knows: a manifest carrying keys from a newer release
 * hard-fails older binaries (upgrade before pushing new keys). Reads are
 * memoised per process run; there is no local working copy at runtime (the
 * pull/push commands manage one for edits).
 */
class EnvManifest
{
    /**
     * The manifest's name in the bucket and on disk —
     * yolo-environment-{environment}.yml (yolo.yml = the app,
     * yolo-environment-production.yml = the production environment).
     */
    public static function filename(): string
    {
        return sprintf('yolo-environment-%s.yml', Helpers::environment());
    }

    /**
     * The complete set of valid env-manifest keys as dot-paths, mirroring the
     * app manifest's allow-list discipline. `services` is the extension point:
     * each env-backed Service case contributes its own `services.{name}` key,
     * so adding a service never edits this class — the enum already knows
     * which services have an env-shared half to declare.
     *
     * @return array<int, string>
     */
    protected static function allowedKeys(): array
    {
        $serviceKeys = array_map(
            fn (Service $service): string => 'services.' . $service->value,
            array_filter(Service::cases(), fn (Service $service): bool => $service->envBacked()),
        );

        return ['domain', 'services', ...$serviceKeys];
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
     * The parsed manifest, pulled fresh from S3 once per run. A missing
     * object (or missing bucket, on a greenfield plan pass) reads as an empty
     * manifest — nothing declared — rather than an error, so consumers can
     * skip cleanly before the first sync seeds the file. Only the genuine
     * not-found set reads as absence: AccessDenied or a transient fault must
     * fail the sync, never silently report the environment as undeclared.
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
            // The bucket copy outlives any one release — a key this binary
            // doesn't know usually means the manifest was pushed by a newer
            // YOLO, not that the file is broken.
            throw new IntegrityCheckException($e->getMessage() . ' The bucket manifest may have been written by a newer YOLO release — update codinglabsau/yolo and retry.', $e->getCode(), $e);
        }
    }

    /**
     * Parse and validate manifest contents. Shared by current() (the S3 read)
     * and environment:manifest:push (which validates the local working copy before
     * uploading, so a misshapen manifest can't reach the bucket at all).
     *
     * @return array<string, mixed>
     */
    public static function parse(string $contents): array
    {
        $manifest = Yaml::parse($contents) ?? [];

        if (! is_array($manifest)) {
            throw new IntegrityCheckException(sprintf('%s must be a YAML map.', static::filename()));
        }

        // The app manifest's `services: [ivs]` list shape flattens to the bare
        // (allowed) `services` path, so without this gate it would validate
        // clean and then provision nothing — same key, opposite shapes.
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

        return $manifest;
    }

    /**
     * Keys present in the manifest that aren't in the schema. Empty array
     * means the shape is valid.
     *
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

    /**
     * Whether the manifest object exists in the env config bucket — distinct
     * from current(), which treats absence as an empty manifest. The seed step
     * uses this to create the file exactly once and never touch it again.
     */
    public static function remoteExists(): bool
    {
        try {
            Aws::s3()->headObject([
                'Bucket' => Paths::s3EnvConfigBucket(),
                'Key' => static::filename(),
            ]);

            return true;
        } catch (S3Exception $e) {
            // Absence only — a denied or failed read must not report the
            // manifest missing, or the seed step would overwrite the
            // operator's file with the stub.
            if (S3::isNotFound($e)) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * The default manifest the first sync seeds — seed-only, so operator edits
     * always stick (the WAF IP-set semantics).
     */
    public static function seedContents(): string
    {
        return (string) file_get_contents(Paths::stubs('yolo-environment.yml.stub'));
    }

    /**
     * The local working-copy path the pull/push commands edit through — the
     * same yolo-environment-{environment}.yml name as the bucket key
     * (gitignored via yolo-environment-*.yml, which never matches the app
     * manifest yolo.yml).
     */
    public static function localPath(): string
    {
        return Paths::base(static::filename());
    }

    /**
     * Forget the memoised manifest — tests bind fresh S3 mocks per case.
     */
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
