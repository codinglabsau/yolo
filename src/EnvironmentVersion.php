<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

use Aws\S3\Exception\S3Exception;

/**
 * The newest YOLO release that has synced the environment, stamped as a marker object in the
 * env config bucket. Catches silent version skew: a stale checkout can run an OLD binary
 * against an environment a NEWER release reconciled, plan "in sync" because it doesn't know
 * the newer checks, and reconcile a newer default back to its old value. The env and account
 * tiers refuse on provable skew ({@see Commands\Command::ensureCliNotOlderThanEnvironment});
 * the app tier only warns ({@see skewWarnings}), since an app pin lagging the environment is
 * the normal state between releases. Only tagged releases advance the stamp.
 */
class EnvironmentVersion
{
    public const string MARKER_KEY = 'yolo-version';

    /** @var string|null|false memoised marker — false = not yet read */
    protected static string|null|false $stamped = false;

    /**
     * Null when never stamped or when the marker can't be read (no bucket on a greenfield plan
     * pass, a tier fenced from the bucket). The broad swallow is deliberate — an unreadable
     * marker fails open, so a fenced read-only tier is never refused for what it can't see.
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
     * The stamped release this CLI is provably behind, or null when it's current or when
     * either side is unordered (a dev pin, an unstamped or unreadable environment).
     */
    public static function outrunBy(?string $cliVersion = null): ?string
    {
        $cli = $cliVersion ?? Helpers::version();

        if (! Helpers::isReleaseVersion($cli)) {
            return null;
        }

        $stamped = static::stamped();

        if ($stamped === null || ! Helpers::isReleaseVersion($stamped)) {
            return null;
        }

        return version_compare(ltrim($cli, 'v'), ltrim($stamped, 'v'), '<') ? $stamped : null;
    }

    /**
     * The app tier's advisory form of the skew check.
     *
     * @return array<int, string>
     */
    public static function skewWarnings(?string $cliVersion = null): array
    {
        $stamped = static::outrunBy($cliVersion);

        if ($stamped === null) {
            return [];
        }

        return [sprintf(
            'This yolo CLI (%s) is OLDER than the release that last synced this environment (%s) — its checks predate that release, so this plan can read "in sync" while missing work a current CLI would flag. Update codinglabsau/yolo in this checkout before trusting it.',
            $cliVersion ?? Helpers::version(),
            $stamped,
        )];
    }

    /** Tests bind fresh S3 mocks per case. */
    public static function reset(): void
    {
        static::$stamped = false;
    }
}
