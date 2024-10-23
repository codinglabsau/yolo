<?php

namespace Codinglabs\Yolo;

class Paths
{
    public static function base($path = null): string
    {
        return BASE_PATH . ($path ? '/' . ltrim($path, '/') : '');
    }

    public static function yolo($path = null): string
    {
        return static::base('/.yolo' . ($path ? '/' . ltrim($path, '/') : ''));
    }

    public static function build($path = null): string
    {
        return static::yolo('build' . ($path ? '/' . ltrim($path, '/') : ''));
    }

    public static function stubs($path = null): string
    {
        return __DIR__ . '/stubs' . ($path ? '/' . ltrim($path, '/') : '');
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

    public static function cloudfront(string $appVersion): string
    {
        return Manifest::get('aws.cloudfront') . '/' . static::versionedBuildAssets($appVersion);
    }

    public static function s3BuildBucket(): string
    {
        return Manifest::get('aws.bucket');
    }

    public static function s3BuildAssets(string $appVersion): string
    {
        return 's3://' . static::s3BuildBucket() . '/' . static::versionedBuildAssets($appVersion) . '/assets';
    }

    public static function s3ArtefactsBucket(): ?string
    {
        return Manifest::get('aws.artefacts-bucket');
    }

    public static function s3Artefacts(string $appVersion, $path = null): string
    {
        return 'artefacts/' . $appVersion . ($path ? '/' . ltrim($path, '/') : '');
    }

    protected static function versionedBuildAssets(string $appVersion): string
    {
        return 'builds/' . $appVersion;
    }
}
