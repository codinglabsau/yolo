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

    /** The installed version, so the version-of-record fence compares what's actually deployed. */
    public static function version(): string
    {
        try {
            return (string) InstalledVersions::getPrettyVersion('codinglabsau/yolo');
        } catch (\OutOfBoundsException) {
            return 'unknown';
        }
    }

    /**
     * Tagged releases only (pre-releases included) — a moving branch pin (`dev-main`,
     * `1.0.x-dev`) can't be a monotonic version-of-record.
     */
    public static function isReleaseVersion(string $version): bool
    {
        return $version !== 'unknown'
            && ! str_starts_with($version, 'dev-')
            && ! str_ends_with($version, '-dev');
    }

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

    /**
     * Call on raw text *before* wrapping in colour tags, so a cut never lands mid-tag.
     * One log event in, exactly one row out.
     */
    public static function truncate(string $text, int $width): string
    {
        $text = trim((string) preg_replace(
            ['/\e\[[0-9;?]*[ -\/]*[@-~]/', '/\s+/u'],
            ['', ' '],
            $text,
        ));

        if ($width <= 0) {
            return '';
        }

        return mb_strlen($text) <= $width
            ? $text
            : mb_substr($text, 0, max(0, $width - 1)) . '…';
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
            // exclusive: yolo-{env}-{app}[-{name}]; shared: yolo-{env}[-{name}]
            $exclusive ? Manifest::name() : null,
            $name,
        ]));
    }

    /**
     * Scope is null for a solo app, `'landlord'` or a tenant id for a multi-tenant one. The
     * `default` tier takes the naked scope name (it's Laravel's default queue); other tiers
     * suffix it. Provisioning and the worker's --queue chain both read this, so the queues
     * created and drained can never drift.
     *
     * @return array<int, string>
     */
    public static function queueNames(?string $scope = null): array
    {
        $tiers = Manifest::queueTiers();

        if ($tiers === []) {
            return [static::queueName($scope)];
        }

        return array_map(fn (string $tier): string => static::queueName($scope, $tier), $tiers);
    }

    /**
     * Comma-joined so `queue:work` drains strict-priority. A solo app with no tiers returns
     * null — the bare worker drains the pinned SQS_QUEUE.
     */
    public static function queueChain(?string $scope = null): ?string
    {
        if ($scope === null && Manifest::queueTiers() === []) {
            return null;
        }

        return implode(',', static::queueNames($scope));
    }

    /** What a solo app pins as SQS_QUEUE; never changes when tiers are added. */
    public static function defaultQueueName(?string $scope = null): string
    {
        return static::queueName($scope, 'default');
    }

    protected static function queueName(?string $scope, ?string $tier = null): string
    {
        $suffix = implode('-', array_filter([$scope, $tier === 'default' ? null : $tier]));

        return static::keyedResourceName($suffix !== '' ? $suffix : null);
    }

    /**
     * Bucket names share one global namespace across every AWS account, so they carry the
     * account id — without it the first account to create yolo-{env}-… owns the name and
     * every other 409s on CreateBucket.
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
     * For the deployer OIDC trust: an explicit manifest `repository` wins (monorepos, forks),
     * then CI's GITHUB_REPOSITORY, then the GitHub origin remote. Null when undeterminable —
     * the caller decides whether that's fatal.
     */
    public static function githubRepository(): ?string
    {
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

    /** For capacity floors that may legitimately be zero (a queue that scales to zero). */
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
     * Ignores the key ordering AWS reshuffles on read; list order is preserved (it can be meaningful).
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
            if (! array_key_exists($key, $actual)) {
                return true;
            }

            if (is_array($value)) {
                if (! is_array($actual[$key]) || static::payloadHasDifferences($value, $actual[$key])) {
                    return true;
                }
            } elseif ($value !== $actual[$key]) {
                return true;
            }
        }

        return false;
    }
}
