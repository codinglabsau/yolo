<?php

namespace Codinglabs\Yolo;

use Illuminate\Container\Container;

class Helpers
{
    public static function app($name = null)
    {
        return $name
            ? Container::getInstance()->make($name)
            : Container::getInstance();
    }

    public static function keyedEnv(string $key): ?string
    {
        $environment = strtoupper(static::environment());

        return env("YOLO_{$environment}_$key");
    }

    public static function keyedResourceName(string $name = null, $exclusive = true): string
    {
        if ($exclusive) {
            // exclusive assets are specific to the current application
            return $name
                ? sprintf("yolo-%s-%s-%s", static::environment(), Manifest::name(), $name)
                : sprintf("yolo-%s-%s", static::environment(), Manifest::name());
        }

        // non-exclusive assets are shared across multiple yolo applications on the same AWS account
        return $name
            ? sprintf("yolo-%s-%s", static::environment(), $name)
            : sprintf("yolo-%s", static::environment());
    }

    public static function manifestName(): string
    {
        return 'yolo.yml';
    }

    public static function versionName(): string
    {
        return 'APP_VERSION';
    }

    public static function artefactName(): string
    {
        return 'artefact.tar.gz';
    }

    public static function environment(): string
    {
        return static::app('environment');
    }
}
