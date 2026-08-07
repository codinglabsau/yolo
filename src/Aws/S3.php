<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Resources\S3\S3Bucket;
use Codinglabs\Yolo\Exceptions\IntegrityCheckException;

class S3
{
    public static function bucketExists(string $name): bool
    {
        return Aws::s3()->doesBucketExistV2($name);
    }

    /**
     * Whether THIS account owns the bucket — the only question S3 answers
     * unambiguously. HeadBucket can't: because the bucket namespace is global, a
     * 403 means both "another account owns this name" and "yours, but the calling
     * tier may not read it", and adopting the former looks like a clean sync that
     * then fails every runtime write. ListBuckets returns only our own buckets, so
     * absence from it is a definitive no. `s3:ListAllMyBuckets` is granted to the
     * read tier, so every command can ask.
     */
    public static function accountOwnsBucket(string $name): bool
    {
        return in_array($name, static::ownedBucketNames(), true);
    }

    /**
     * Every bucket name in this account.
     *
     * @return array<int, string>
     */
    public static function ownedBucketNames(): array
    {
        return collect(Aws::s3()->listBuckets()['Buckets'] ?? [])
            ->pluck('Name')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Whether the name is taken anywhere in S3's global namespace. Used only to
     * tell "free" from "owned by someone else" once {@see accountOwnsBucket} has
     * established it isn't ours, so the operator gets the real reason. Exists and
     * unreadable (403) and exists in another region (PermanentRedirect) both count
     * as taken; only a 404 is free. An unexpected failure reads as taken — it makes
     * the wording of an error we are already emitting more cautious, never less.
     */
    public static function bucketTaken(string $name): bool
    {
        try {
            return Aws::s3()->doesBucketExistV2($name, accept403: true);
        } catch (S3Exception $e) {
            return ! static::isNotFound($e);
        }
    }

    /**
     * Whether S3 would accept the name at CreateBucket time, checked up front so a
     * typo fails manifest validation instead of surfacing as a mid-apply
     * InvalidBucketName. Covers the general-purpose bucket rules: 3-63 characters
     * of lowercase alphanumerics, dots and hyphens, starting and ending
     * alphanumeric, no adjacent dots, and not shaped like an IP address.
     */
    public static function isValidBucketName(string $name): bool
    {
        if (strlen($name) < 3 || strlen($name) > 63) {
            return false;
        }

        if (str_contains($name, '..') || preg_match('/^(\d{1,3}\.){3}\d{1,3}$/', $name) === 1) {
            return false;
        }

        return preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $name) === 1;
    }

    /**
     * Delete a bucket — the single chokepoint every YOLO bucket teardown routes
     * through, guarded so the bring-your-own application data bucket (AWS_BUCKET)
     * can NEVER be deleted: it holds user data and is not YOLO's to remove. A name
     * match against the configured app data bucket is a hard integrity failure, the
     * runtime last line of defence behind {@see S3Bucket}
     * being non-deletable. (Config / asset / logs buckets are regeneratable and pass.)
     */
    public static function deleteBucket(string $name): void
    {
        if (Manifest::has('bucket') && $name === Paths::s3AppBucket()) {
            throw new IntegrityCheckException(sprintf(
                'Refusing to delete "%s": it is the application data bucket, which YOLO never deletes.',
                $name,
            ));
        }

        Aws::s3()->deleteBucket(['Bucket' => $name]);
    }

    /**
     * Whether an S3 failure means the object/bucket genuinely doesn't exist —
     * NoSuchKey / NoSuchBucket, or a bare 404 (HeadObject carries no error
     * code in its response body). AccessDenied, throttling and transient
     * faults are NOT absence and must never be read as it: callers treating
     * this as "nothing there" rethrow everything else.
     */
    public static function isNotFound(S3Exception $e): bool
    {
        return in_array($e->getAwsErrorCode(), ['NoSuchKey', 'NoSuchBucket'], true)
            || $e->getStatusCode() === 404;
    }

    /**
     * The bucket's Block Public Access configuration, or null when none is set
     * (a fresh bucket has none — AWS throws NoSuchPublicAccessBlockConfiguration).
     *
     * @return array<string, bool>|null
     */
    public static function publicAccessBlock(string $bucket): ?array
    {
        try {
            return Aws::s3()->getPublicAccessBlock(['Bucket' => $bucket])['PublicAccessBlockConfiguration'] ?? null;
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() === 'NoSuchPublicAccessBlockConfiguration') {
                return null;
            }

            throw $e;
        }
    }

    /**
     * The bucket's versioning status ('Enabled' / 'Suspended'), or null when
     * versioning was never configured (the result simply omits Status).
     */
    public static function bucketVersioning(string $bucket): ?string
    {
        return Aws::s3()->getBucketVersioning(['Bucket' => $bucket])['Status'] ?? null;
    }

    /**
     * The bucket's CORS rules, or null when none are configured (AWS throws
     * NoSuchCORSConfiguration).
     *
     * @return array<int, array<string, mixed>>|null
     */
    public static function bucketCors(string $bucket): ?array
    {
        try {
            return Aws::s3()->getBucketCors(['Bucket' => $bucket])['CORSRules'] ?? null;
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() === 'NoSuchCORSConfiguration') {
                return null;
            }

            throw $e;
        }
    }

    /**
     * The bucket's lifecycle rules, or null when none are configured (AWS
     * throws NoSuchLifecycleConfiguration).
     *
     * @return array<int, array<string, mixed>>|null
     */
    public static function lifecycleRules(string $bucket): ?array
    {
        try {
            return Aws::s3()->getBucketLifecycleConfiguration(['Bucket' => $bucket])['Rules'] ?? null;
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() === 'NoSuchLifecycleConfiguration') {
                return null;
            }

            throw $e;
        }
    }

    /**
     * The bucket's resource policy decoded to an array, or null when none is
     * attached (AWS throws NoSuchBucketPolicy) — or when the bucket itself
     * doesn't exist yet (NoSuchBucket): the plan pass may read the policy of
     * a sibling bucket the apply pass hasn't created yet (e.g. the asset
     * distribution reading a renamed asset bucket on a migration's first
     * sync), and a missing bucket means a missing policy. If the bucket
     * genuinely never appears, the apply-phase put fails loudly.
     *
     * @return array<string, mixed>|null
     */
    public static function bucketPolicy(string $bucket): ?array
    {
        try {
            $policy = Aws::s3()->getBucketPolicy(['Bucket' => $bucket])['Policy'] ?? null;

            return $policy === null ? null : json_decode((string) $policy, true);
        } catch (S3Exception $e) {
            if (in_array($e->getAwsErrorCode(), ['NoSuchBucketPolicy', 'NoSuchBucket'], true)) {
                return null;
            }

            throw $e;
        }
    }
}
