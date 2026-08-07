<?php

declare(strict_types=1);

namespace Codinglabs\Yolo;

use Aws\S3\Exception\S3Exception;

/**
 * The environment's version-of-record — the newest YOLO release that has
 * synced it, stamped as a marker object in the env config bucket.
 *
 * It exists to catch silent version skew: sync's drift checks are only as
 * current as the CLI running them, so a checkout with a stale vendor can run
 * an OLD binary against an environment a NEWER release has since reconciled —
 * and report "in sync" simply because it doesn't know about the newer checks.
 * The stamp gives every sync a way to notice that and say so out loud
 * ({@see skewWarnings}); it never blocks, because an older CLI's writes are
 * still valid — it's the older CLI's *silence* that misleads.
 *
 * Only tagged releases advance the stamp — a `dev-*` branch pin isn't a
 * monotonic version, so it can neither set the record nor be compared to it.
 */
class EnvironmentVersion
{
    /** The marker object's key in the env config bucket. */
    public const string MARKER_KEY = 'yolo-version';

    /** @var string|null|false memoised marker — false = not yet read */
    protected static string|null|false $stamped = false;

    /**
     * The stamped version-of-record, or null when the environment has never
     * been stamped — or when the marker simply can't be read (no bucket on a
     * greenfield plan pass, or a tier fenced from the config bucket). The
     * broad swallow is deliberate: this read is advisory, never load-bearing —
     * a tier that can't read the marker just doesn't get the skew warning,
     * and the stamp step re-writing an unreadable marker is idempotent.
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
     * The loud-but-soft skew advisory for every sync tier's plan: warns when
     * the running CLI is a release provably OLDER than the environment's
     * version-of-record. Never a refusal — see the class doc for why — and
     * silent when either side is unordered (a dev pin, an unstamped env).
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

    /**
     * Forget the memoised marker — tests bind fresh S3 mocks per case.
     */
    public static function reset(): void
    {
        static::$stamped = false;
    }
}
