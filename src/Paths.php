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

    public static function buildAssets(): string
    {
        return static::build('public/assets');
    }

    public static function manifest(): string
    {
        return static::base(Helpers::manifestName());
    }

    public static function version(): string
    {
        return static::build(Helpers::versionName());
    }

    public static function artefact(): string
    {
        return static::yolo(Helpers::artefactName());
    }

    public static function s3AppBucket(): string
    {
        return Manifest::get('bucket');
    }

    public static function s3BuildAssets(string $appVersion): string
    {
        return 's3://' . static::s3AppBucket() . '/' . static::versionedBuildAssets($appVersion) . '/assets';
    }

    public static function yoloDir(): string
    {
        return sprintf('/home/ubuntu/yolo/%s', Helpers::keyedResourceName());
    }

    public static function logDir(): string
    {
        return sprintf('/var/log/yolo/%s', Helpers::keyedResourceName());
    }

    public static function s3ArtefactsBucket(): ?string
    {
        return Manifest::get('artefacts-bucket', Helpers::keyedResourceName('artefacts'));
    }

    /**
     * Env-scoped bucket holding the shared ALB's access logs. Separate from
     * the per-app artefacts bucket because the ALB itself is env-shared — a
     * per-app log destination would make multiple apps fight over a single
     * ALB attribute (`access_logs.s3.bucket` is one value, last-writer-wins),
     * and the ELB log-delivery bucket policy needs to live in the same scope
     * as the ALB so sync's account → environment → app ordering can write it
     * before the ALB attribute write that depends on it.
     */
    public static function s3LoadBalancerLogsBucket(): string
    {
        return Manifest::get('alb-logs-bucket', sprintf('yolo-%s-alb-logs', Helpers::environment()));
    }

    public static function s3Artefacts(string $appVersion, $path = null): string
    {
        return 'artefacts/' . $appVersion . ($path ? '/' . ltrim((string) $path, '/') : '');
    }

    protected static function versionedBuildAssets(string $appVersion): string
    {
        return 'builds/' . $appVersion;
    }
}
