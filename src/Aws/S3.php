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
