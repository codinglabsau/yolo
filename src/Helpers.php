<?php

namespace Codinglabs\Yolo;

use BackedEnum;
use Illuminate\Container\Container;

class Helpers
{
    public static function app($name = null)
    {
        return $name
            ? Container::getInstance()->make($name)
            : Container::getInstance();
    }

    public static function keyedEnvName(string $key): ?string
    {
        $environment = strtoupper(static::environment());

        return "YOLO_{$environment}_$key";
    }

    public static function keyedEnv(string $key): ?string
    {
        return env(static::keyedEnvName($key));
    }

    public static function keyedResourceName(string|BackedEnum $name = null, $exclusive = true, string $seperator = '-'): string
    {
        if ($name instanceof BackedEnum) {
            $name = $name->value;
        }

        if ($exclusive) {
            // exclusive resources are specific to the current application;
            // e.g. yolo-production-<app-name> or yolo-production-<app-name>-<resource-name>
            return $name
                ? sprintf("yolo$seperator%s$seperator%s$seperator%s", static::environment(), Manifest::name(), $name)
                : sprintf("yolo$seperator%s$seperator%s", static::environment(), Manifest::name());
        }

        // non-exclusive resources are shared across multiple yolo applications on the same AWS account;
        // e.g. yolo-production or yolo-production-<resource-name>
        return $name
            ? sprintf("yolo$seperator%s$seperator%s", static::environment(), $name)
            : sprintf("yolo$seperator%s", static::environment());
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

    public static function environment(): ?string
    {
        if (! static::app()->has('environment')) {
            return null;
        }

        return static::app('environment');
    }

    public static function payloadHasDifferences(array $expected, array $actual): bool
    {
        foreach ($expected as $key => $value) {
            // check if the key exists in the second array
            if (! array_key_exists($key, $actual)) {
                // Key not found
                return true;
            }

            // if the value is an array, call the function recursively
            if (is_array($value)) {
                if (! is_array($actual[$key]) || static::payloadHasDifferences($value, $actual[$key])) {
                    // recursive comparison failed or not an array
                    return true;
                }
            } else {
                // compare the values directly
                if ($value !== $actual[$key]) {
                    // values do not match
                    return true;
                }
            }
        }

        // all keys and values matched
        return false;
    }
}
