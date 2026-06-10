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
}
