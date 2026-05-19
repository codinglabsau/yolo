<?php

namespace Codinglabs\Yolo;

use BackedEnum;
use Illuminate\Container\Container;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

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

    public static function keyedResourceName(string|BackedEnum|null $name = null, $exclusive = true, string $seperator = '-'): string
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

    public static function validatePositiveInt(mixed $value, string $key): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($validated === false) {
            throw new IntegrityCheckException(sprintf(
                '%s must be a positive integer (got %s)',
                $key,
                json_encode($value),
            ));
        }

        return $validated;
    }

    public static function validateCloudWatchLogRetention(mixed $value, string $key): int
    {
        $allowed = [1, 3, 5, 7, 14, 30, 60, 90, 120, 150, 180, 365, 400, 545, 731, 1827, 2192, 2557, 2922, 3288, 3653];

        $validated = filter_var($value, FILTER_VALIDATE_INT);

        if ($validated === false || ! in_array($validated, $allowed, true)) {
            throw new IntegrityCheckException(sprintf(
                '%s must be one of CloudWatch Logs retention values [%s] (got %s)',
                $key,
                implode(', ', $allowed),
                json_encode($value),
            ));
        }

        return $validated;
    }

    public static function validateStrictBool(mixed $value, string $key): bool
    {
        $validated = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($validated === null) {
            throw new IntegrityCheckException(sprintf(
                '%s must be a boolean (got %s)',
                $key,
                json_encode($value),
            ));
        }

        return $validated;
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
