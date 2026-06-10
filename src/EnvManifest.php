<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

use Illuminate\Support\Arr;
use Symfony\Component\Yaml\Yaml;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

/**
 * The environment manifest — env-shared desired state, one yolo-{environment}.yml
 * per environment, stored in the env config bucket rather than any app's repo.
 * The app manifest (yolo.yml) declares what one app needs; this declares what
 * the environment provides (shared-service ingress domain, env services and
 * their sizing). The environment is in the filename, so a pulled copy can never
 * be pushed at the wrong environment.
 *
 * Every sync pulls it fresh from S3 and reconciles toward it, so any YOLO
 * version converges on the environment's own declared truth — the version-skew
 * problem that forced env resources to freeze config at create doesn't exist
 * for resources declared here. Reads are memoised per process run; there is no
 * local working copy at runtime (the pull/push commands manage one for edits).
 */
class EnvManifest
{
    /**
     * The manifest's name in the bucket and on disk — yolo-{environment}.yml
     * (yolo.yml = the app, yolo-production.yml = the production environment).
     */
    public static function filename(): string
    {
        return sprintf('yolo-%s.yml', Helpers::environment());
    }

    /**
     * The complete set of valid env-manifest keys as dot-paths — the single
     * source of truth for the file's shape, mirroring the app manifest's
     * allow-list discipline. `services` is the extension point env services
     * declare under; it ships as an empty map until the first service lands.
     *
     * @var array<int, string>
     */
    protected const ALLOWED_KEYS = ['domain', 'services'];

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
     * skip cleanly before the first sync seeds the file.
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
        } catch (S3Exception) {
            return static::$loaded = [];
        }

        return static::$loaded = static::parse($body);
    }

    /**
     * Parse and validate manifest contents. Shared by current() (the S3 read)
     * and env:push --shared (which validates the local working copy before
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
        } catch (S3Exception) {
            return false;
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
     * same yolo-{environment}.yml name as the bucket key (gitignored via
     * yolo-*.yml, which never matches the dash-less app manifest yolo.yml).
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
        foreach (static::ALLOWED_KEYS as $allowed) {
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
