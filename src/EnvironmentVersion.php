<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

use Aws\S3\Exception\S3Exception;

/**
 * The newest YOLO release that has synced the environment, stamped as a marker object in the
 * env config bucket. Catches silent version skew: a stale checkout can run an OLD binary
 * against an environment a NEWER release reconciled and report "in sync" only because it
 * doesn't know the newer checks. Advisory only ({@see skewWarnings}) — an older CLI's writes
 * are still valid; it's its silence that misleads. Only tagged releases advance the stamp.
 */
class EnvironmentVersion
{
    public const string MARKER_KEY = 'yolo-version';

    /** @var string|null|false memoised marker — false = not yet read */
    protected static string|null|false $stamped = false;

    /**
     * Null when never stamped or when the marker can't be read (no bucket on a greenfield plan
     * pass, a tier fenced from the bucket). The broad swallow is deliberate: advisory, never
     * load-bearing.
     */
    public static function stamped(): ?string
    {
        if (static::$stamped !== false) {
            return static::$stamped;
        }

        try {
            $body = (string) Aws::s3()->getObject([
                'Bucket' => Paths::s3EnvConfigBucket(),
                'Key' => self::MARKER_KEY,
            ])['Body'];
        } catch (S3Exception) {
            return static::$stamped = null;
        }

        $version = trim($body);

        return static::$stamped = ($version !== '' ? $version : null);
    }

    public static function stamp(string $version): void
    {
        Aws::s3()->putObject([
            'Bucket' => Paths::s3EnvConfigBucket(),
            'Key' => self::MARKER_KEY,
            'Body' => $version . "\n",
        ]);

        static::$stamped = $version;
    }

    /**
     * Never a refusal; silent when either side is unordered (a dev pin, an unstamped env).
     *
     * @return array<int, string>
     */
    public static function skewWarnings(?string $cliVersion = null): array
    {
        $cli = $cliVersion ?? Helpers::version();

        if (! Helpers::isReleaseVersion($cli)) {
            return [];
        }

        $stamped = static::stamped();

        if ($stamped === null || ! Helpers::isReleaseVersion($stamped)) {
            return [];
        }

        if (version_compare(ltrim($cli, 'v'), ltrim($stamped, 'v'), '>=')) {
            return [];
        }

        return [sprintf(
            'This yolo CLI (%s) is OLDER than the release that last synced this environment (%s) — its checks predate that release, so this plan can read "in sync" while missing work a current CLI would flag. Update codinglabsau/yolo in this checkout before trusting it.',
            $cli,
            $stamped,
        )];
    }

    /** Tests bind fresh S3 mocks per case. */
    public static function reset(): void
    {
        static::$stamped = false;
    }
}
