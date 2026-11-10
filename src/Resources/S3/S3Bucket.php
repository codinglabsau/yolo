<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\S3;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Undeletable;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

/**
 * The optional application data bucket (AWS_BUCKET). `bucket: true` — YOLO
 * creates it inside the `yolo-*` fence the admin tier may write; `bucket:
 * some-name` — a BYO bucket YOLO never creates (outside `yolo-*`, so CreateBucket
 * and the hardening writes would AccessDenied; its step skips before reaching
 * this resource).
 *
 * Create-only in both modes for everything except versioning — never deleted.
 * Block Public Access and CORS are set once because it holds user data and an
 * app may legitimately change its own CORS or serve public objects; tags are
 * never written either, for the same reason as below.
 *
 * Versioning is the one attribute reconciled on every sync: the Developer tier
 * holds `s3:DeleteObject` on this bucket, and versioning is the only recovery
 * path from a bad delete short of an Admin-tier backup restore — so it
 * defaults on at create and self-heals if ever found disabled.
 *
 * Never YOLO-tagged: a tag would drag it into `yolo audit` as a permanent
 * "unexpected" finding after `destroy:app` deliberately leaves it standing.
 * Undeletable is backed by the name guard in {@see S3::deleteBucket()} and by
 * the admin tier's destructive S3 grants covering only the regeneratable
 * bucket suffixes.
 */
class S3Bucket implements Resource, SynchronisesConfiguration, Undeletable
{
    use ReconcilesBucketHardening;

    public function name(): string
    {
        return Paths::s3AppBucket();
    }

    public function tags(): array
    {
        return [];
    }

    public function scope(): Scope
    {
        return Scope::App;
    }

    /**
     * ListBuckets rather than HeadBucket: HeadBucket authorises on s3:ListBucket,
     * which the read tiers deliberately don't hold on the data bucket (it would
     * enumerate user uploads), and a 403 there would read as "missing" and plan a
     * create every sync. ListBuckets returns only our own buckets, which is the
     * question anyway.
     */
    public function exists(): bool
    {
        return S3::accountOwnsBucket($this->name());
    }

    public function arn(): string
    {
        return 'arn:aws:s3:::' . $this->name();
    }

    public function create(): void
    {
        Aws::s3()->createBucket([
            'Bucket' => $this->name(),
        ]);

        Aws::s3()->waitUntil('BucketExists', [
            'Bucket' => $this->name(),
        ]);

        Aws::s3()->putPublicAccessBlock([
            'Bucket' => $this->name(),
            'PublicAccessBlockConfiguration' => Aws::publicAccessBlockConfiguration(),
        ]);

        Aws::s3()->putBucketCors([
            'Bucket' => $this->name(),
            'CORSConfiguration' => ['CORSRules' => [$this->desiredCors()]],
        ]);

        $this->synchroniseConfiguration();
    }

    /**
     * Tags are never reconciled, so the tier needs no S3 tag permission on an
     * adopted bucket.
     */
    public function synchroniseTags(bool $apply): array
    {
        return [];
    }

    /**
     * The one piece of live config this create-only resource still reconciles
     * — see the class docblock for why versioning is the exception.
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        return $this->reconcileVersioning($apply);
    }

    /**
     * Permissive origins: the presigned URL is the real gate, not bucket CORS.
     * ExposeHeaders is deliberately omitted — add ['ETag'] only for browser
     * multipart uploads.
     *
     * @return array<string, mixed>
     */
    protected function desiredCors(): array
    {
        return [
            'AllowedOrigins' => ['*'],
            'AllowedMethods' => ['GET', 'PUT', 'HEAD'],
            'AllowedHeaders' => ['*'],
            'MaxAgeSeconds' => 3600,
        ];
    }
}
