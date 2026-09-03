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
    /**
     * Retrying AccessDenied normally masks a real permission gap. The carve-out mirrors
     * {@see WafV2::retryWhileLoggingPermissionsPropagate}: sync widens its own tier's policy
     * earlier in the same apply pass, and a new default policy version reaches the
     * authorization plane seconds after the control-plane write — so the first sync of a
     * release that adds a grant races that grant. A real gap still fails, just after the
     * bounded window.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public static function retryWhilePermissionsPropagate(callable $operation, int $maxAttempts = 5, int $sleepSeconds = 5): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $operation();
            } catch (S3Exception $exception) {
                $attempt++;

                if ($attempt >= $maxAttempts || $exception->getAwsErrorCode() !== 'AccessDenied') {
                    throw $exception;
                }

                sleep($sleepSeconds);
            }
        }
    }

    public static function bucketExists(string $name): bool
    {
        return Aws::s3()->doesBucketExistV2($name);
    }

    /**
     * HeadBucket can't answer this: the bucket namespace is global, so a 403 means
     * both "another account owns this name" and "ours, but this tier can't read
     * it" — and adopting the former looks like a clean sync that then fails every
     * runtime write. ListBuckets returns only our own buckets.
     */
    public static function accountOwnsBucket(string $name): bool
    {
        return in_array($name, static::ownedBucketNames(), true);
    }

    /**
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
     * Only tells "free" from "owned by someone else" once {@see accountOwnsBucket}
     * has said it isn't ours. 403 and PermanentRedirect both count as taken; only
     * a 404 is free. An unexpected failure reads as taken — it makes the wording
     * of an error already being emitted more cautious, never less.
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
     * Checked up front so a typo fails manifest validation instead of surfacing
     * as a mid-apply InvalidBucketName.
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
     * The single chokepoint for every bucket teardown: the bring-your-own app
     * data bucket holds user data and is never YOLO's to remove — the runtime
     * last line of defence behind {@see S3Bucket} being non-deletable.
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
     * A bare 404 counts because HeadObject carries no error code in its body.
     * AccessDenied, throttling and transient faults are NOT absence — callers
     * rethrow everything else.
     */
    public static function isNotFound(S3Exception $e): bool
    {
        return in_array($e->getAwsErrorCode(), ['NoSuchKey', 'NoSuchBucket'], true)
            || $e->getStatusCode() === 404;
    }

    /**
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

    public static function bucketVersioning(string $bucket): ?string
    {
        return Aws::s3()->getBucketVersioning(['Bucket' => $bucket])['Status'] ?? null;
    }

    /**
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
     * NoSuchBucket is also null: the plan pass may read a sibling bucket's policy
     * before the apply pass has created it. If the bucket never appears, the
     * apply-phase put fails loudly.
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
