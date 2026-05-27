<?php

namespace Codinglabs\Yolo\Aws;

use Codinglabs\Yolo\Aws;
use Aws\S3\Exception\S3Exception;

class S3
{
    public static function bucketExists(string $name): bool
    {
        return Aws::s3()->doesBucketExistV2($name);
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
     * The bucket's resource policy decoded to an array, or null when none is
     * attached (AWS throws NoSuchBucketPolicy).
     *
     * @return array<string, mixed>|null
     */
    public static function bucketPolicy(string $bucket): ?array
    {
        try {
            $policy = Aws::s3()->getBucketPolicy(['Bucket' => $bucket])['Policy'] ?? null;

            return $policy === null ? null : json_decode((string) $policy, true);
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() === 'NoSuchBucketPolicy') {
                return null;
            }

            throw $e;
        }
    }
}
