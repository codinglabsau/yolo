<?php

namespace Codinglabs\Yolo;

use Codinglabs\Yolo\Resources\S3\S3Bucket;

class Paths
{
    public static function base($path = null): string
    {
        return BASE_PATH . ($path ? '/' . ltrim((string) $path, '/') : '');
    }

    public static function yolo($path = null): string
    {
        return static::base('/.yolo' . ($path ? '/' . ltrim((string) $path, '/') : ''));
    }

    public static function build($path = null): string
    {
        return static::yolo('build' . ($path ? '/' . ltrim((string) $path, '/') : ''));
    }

    public static function stubs($path = null): string
    {
        return __DIR__ . '/../stubs' . ($path ? '/' . ltrim((string) $path, '/') : '');
    }

    public static function bin($path = null): string
    {
        return __DIR__ . '/../bin' . ($path ? '/' . ltrim((string) $path, '/') : '');
    }

    public static function manifest(): string
    {
        return static::base(Helpers::manifestName());
    }

    public static function version(): string
    {
        return static::build(Helpers::versionName());
    }

    /**
     * `bucket: true` derives a name in YOLO's keyed namespace (globally unique by construction);
     * any other value is a bring-your-own bucket taken verbatim (see {@see S3Bucket}).
     */
    public static function s3AppBucket(): string
    {
        return Manifest::managesAppBucket()
            ? Helpers::keyedBucketName('data')
            : Manifest::get('bucket');
    }

    public static function s3ConfigBucket(): string
    {
        return Helpers::keyedBucketName('config');
    }

    /**
     * Expiring telemetry, one prefix per log class (`alb/` today). Separate from the config
     * buckets so secrets never share a bucket with an external write principal or an expiry
     * lifecycle. Env-scoped because the shared ALB writes its access-log attribute during the
     * env sync, and the ELB log-delivery bucket policy must already exist by then.
     */
    public static function s3LogsBucket(): string
    {
        return Helpers::keyedBucketName('logs', exclusive: false);
    }

    /**
     * Env-scoped like the logs bucket, but never shared with it: dumps are full database
     * content and must never sit next to an external write principal or a bucket-wide expiry.
     * Each app's task role can write only its own `{app}/` prefix, and read none.
     */
    public static function s3BackupsBucket(): string
    {
        return Helpers::keyedBucketName('backups', exclusive: false);
    }

    /**
     * The env manifest and env-shared `.env`, with the same secrets posture as the per-app
     * config buckets. Read access here gates env-secret control — app deploys never need it.
     */
    public static function s3EnvConfigBucket(): string
    {
        return Helpers::keyedBucketName('config', exclusive: false);
    }

    public static function s3AppEnvKey(): string
    {
        return sprintf('.env.%s', Helpers::environment());
    }

    /** The environment is in the filename so a pulled copy can never be pushed at the wrong environment. */
    public static function s3SharedEnvKey(): string
    {
        return sprintf('.env.environment.%s', Helpers::environment());
    }

    /**
     * YOLO-minted per-app secrets (the Typesense scoped key), kept beside the env manifest
     * rather than in the app's developer `.env` (fenced from the admin tier) or the env-shared
     * `.env` (which carries the cluster admin key the build must never read). One object per
     * app, so each build reads only its own file.
     */
    public static function s3EnvAppEnvKey(?string $app = null): string
    {
        return sprintf('env/.env.%s', $app ?? Manifest::name());
    }

    /** One object per app under `apps/`, so the env tier can list the prefix and union every app's claims. */
    public static function s3AppManifestKey(): string
    {
        return sprintf('apps/%s.yml', Manifest::name());
    }
}
