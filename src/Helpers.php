<?php

namespace Codinglabs\Yolo;

use BackedEnum;
use Composer\InstalledVersions;
use Illuminate\Container\Container;
use Symfony\Component\Process\Process;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

class Helpers
{
    public static function app($name = null)
    {
        return $name
            ? Container::getInstance()->make($name)
            : Container::getInstance();
    }

    /**
     * The running CLI version — the *installed* `codinglabsau/yolo` version, not a
     * hardcoded string, so the version-of-record fence compares what's actually
     * deployed. A tag (e.g. `1.0.0-alpha.35`); a branch pin reports as `dev-*`.
     */
    public static function version(): string
    {
        try {
            return (string) InstalledVersions::getPrettyVersion('codinglabsau/yolo');
        } catch (\OutOfBoundsException) {
            return 'unknown';
        }
    }

    /**
     * Whether a version is a tagged release (including pre-releases like
     * `1.0.0-alpha.5`) rather than a branch/dev pin (`dev-main`, `1.0.x-dev`).
     * The environment and account tiers refuse to advance their version-of-record
     * from a non-release pin — a moving branch can't be a monotonic version.
     */
    public static function isReleaseVersion(string $version): bool
    {
        return $version !== 'unknown'
            && ! str_starts_with($version, 'dev-')
            && ! str_ends_with($version, '-dev');
    }

    /**
     * Render an elapsed-seconds count as a compact "45s" / "3m" / "12m 30s" so a
     * long-running heartbeat or a deployment timer reads naturally past the minute
     * mark. Shared by the stepped-command runner and the status dashboard.
     */
    public static function humaniseElapsed(int $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%ds', $seconds);
        }

        $minutes = intdiv($seconds, 60);
        $remainder = $seconds % 60;

        return $remainder === 0
            ? sprintf('%dm', $minutes)
            : sprintf('%dm %ds', $minutes, $remainder);
    }

    public static function keyedEnvName(string $key): ?string
    {
        $environment = strtoupper((string) static::environment());

        return "YOLO_{$environment}_$key";
    }

    public static function keyedEnv(string $key): ?string
    {
        return env(static::keyedEnvName($key));
    }

    public static function keyedResourceName(string|BackedEnum|null $name = null, bool $exclusive = true): string
    {
        if ($name instanceof BackedEnum) {
            $name = $name->value;
        }

        return implode('-', array_filter([
            'yolo',
            static::environment(),
            // exclusive resources are specific to the current application
            // (yolo-{env}-{app}[-{name}]); non-exclusive resources are shared
            // across the environment (yolo-{env}[-{name}]).
            $exclusive ? Manifest::name() : null,
            $name,
        ]));
    }

    /**
     * S3 bucket names live in a single global namespace shared by every AWS
     * account, so unlike other resource names they carry the account id as a
     * discriminator — without it, whichever account creates yolo-{env}-… first
     * owns the name globally and every other account 409s on CreateBucket.
     * Exclusive → yolo-{account-id}-{env}-{app}[-{name}];
     * shared → yolo-{account-id}-{env}[-{name}].
     */
    public static function keyedBucketName(string|BackedEnum|null $name = null, bool $exclusive = true): string
    {
        if ($name instanceof BackedEnum) {
            $name = $name->value;
        }

        return implode('-', array_filter([
            'yolo',
            Aws::accountId(),
            static::environment(),
            $exclusive ? Manifest::name() : null,
            $name,
        ]));
    }

    public static function manifestName(): string
    {
        return 'yolo.yml';
    }

    public static function versionName(): string
    {
        return 'APP_VERSION';
    }

    public static function environment(): ?string
    {
        if (! static::app()->has('environment')) {
            return null;
        }

        return static::app('environment');
    }

    /**
     * The GitHub `owner/repo` for the deployer OIDC trust, inferred without
     * manifest config: GITHUB_REPOSITORY when running inside Actions, otherwise
     * the GitHub origin remote of the local checkout. Returns null when it can't
     * be determined (no GitHub origin) — the caller decides whether that's fatal.
     */
    public static function githubRepository(): ?string
    {
        // An explicit manifest `repository` wins (monorepos, forks); otherwise
        // infer from CI's GITHUB_REPOSITORY or the GitHub origin remote.
        if ($repository = Manifest::get('repository')) {
            return $repository;
        }

        if ($repository = env('GITHUB_REPOSITORY')) {
            return $repository;
        }

        return static::parseGithubRepository(static::gitOriginUrl());
    }

    public static function gitOriginUrl(): ?string
    {
        $process = new Process(['git', '-C', Paths::base(), 'remote', 'get-url', 'origin']);
        $process->run();

        return $process->isSuccessful()
            ? (trim($process->getOutput()) ?: null)
            : null;
    }

    /**
     * Extract the GitHub `owner/repo` from a remote URL (https or ssh form,
     * with or without the trailing .git), or null if it isn't a github.com remote.
     */
    public static function parseGithubRepository(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        return preg_match('#github\.com[:/]([^/]+/[^/]+?)(?:\.git)?/?$#', $url, $matches)
            ? $matches[1]
            : null;
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

    /**
     * A whole number ≥ 0 — for capacity floors that may legitimately be zero (a
     * queue that scales to zero), where validatePositiveInt's 1-minimum is wrong.
     */
    public static function validateNonNegativeInt(mixed $value, string $key): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        if ($validated === false) {
            throw new IntegrityCheckException(sprintf(
                '%s must be a non-negative integer (got %s)',
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

    /**
     * Compare two policy / config documents for semantic equality, ignoring the
     * key ordering AWS is free to reshuffle on read. Object keys are sorted
     * recursively; list order is preserved (it can be meaningful). Either side
     * may be null (no document present).
     *
     * @param  array<mixed>|null  $a
     * @param  array<mixed>|null  $b
     */
    public static function documentsEqual(?array $a, ?array $b): bool
    {
        return static::canonicaliseDocument($a) === static::canonicaliseDocument($b);
    }

    /**
     * @param  array<mixed>|null  $document
     */
    protected static function canonicaliseDocument(?array $document): ?string
    {
        if ($document === null) {
            return null;
        }

        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(fn ($item) => is_array($item) ? $sort($item) : $item, $value);
        };

        return json_encode($sort($document));
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
            } elseif ($value !== $actual[$key]) {
                // compare the values directly
                // values do not match
                return true;
            }
        }

        // all keys and values matched
        return false;
    }
}
