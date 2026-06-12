<?php

namespace Codinglabs\Yolo;

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

    public static function manifest(): string
    {
        return static::base(Helpers::manifestName());
    }

    public static function version(): string
    {
        return static::build(Helpers::versionName());
    }

    public static function s3AppBucket(): string
    {
        return Manifest::get('bucket');
    }

    public static function s3ConfigBucket(): string
    {
        return Helpers::keyedBucketName('config');
    }

    /**
     * Env-scoped bucket holding expiring telemetry, one prefix per log
     * class — the shared ALB's access logs under `alb/` today; future log
     * types (e.g. WAF) join as sibling prefixes rather than new buckets.
     * Kept separate from the config buckets so secrets never share a bucket
     * with an external write principal or an expiry lifecycle. It lives in
     * the env scope because its first occupant does: the shared ALB writes
     * its `access_logs.s3.bucket` attribute during the env sync, and the ELB
     * log-delivery bucket policy must already exist at that point — sync's
     * account → environment → app ordering guarantees it.
     */
    public static function s3LogsBucket(): string
    {
        return Helpers::keyedBucketName('logs', exclusive: false);
    }

    /**
     * Env-scoped config bucket holding the environment's declaration — the env
     * manifest (yolo-environment-{environment}.yml) and the env-shared `.env`. The env-tier sibling
     * of the per-app config buckets, carrying the same secrets posture:
     * no external write principals, no expiry lifecycle. Read access to this
     * bucket is the permission that gates env-secret control — app deploys
     * never need it.
     */
    public static function s3EnvConfigBucket(): string
    {
        return Helpers::keyedBucketName('config', exclusive: false);
    }

    /**
     * This app's env file in its config bucket — .env.{environment}, the file
     * env:pull/push move and the build stages into the image.
     */
    public static function s3AppEnvKey(): string
    {
        return sprintf('.env.%s', Helpers::environment());
    }

    /**
     * The env-shared .env — generated service secrets, the environment-tier
     * sibling of each app's env file. The same .env.environment.{environment}
     * name in the bucket and on disk, with the environment in the filename so
     * a pulled copy can never be pushed at the wrong environment.
     */
    public static function s3SharedEnvKey(): string
    {
        return sprintf('.env.environment.%s', Helpers::environment());
    }

    /**
     * This app's claim file inside the env config bucket — the published
     * record of which YOLO-provisioned services the app consumes. One object
     * per app under `apps/`, so the env tier can list the prefix and evaluate
     * the union of every app's claims.
     */
    public static function s3AppManifestKey(): string
    {
        return sprintf('apps/%s.yml', Manifest::name());
    }
}
