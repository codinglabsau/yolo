<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\S3;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Undeletable;

/**
 * The optional application data bucket (AWS_BUCKET). `bucket: true` — YOLO
 * creates it inside the `yolo-*` fence the admin tier may write; `bucket:
 * some-name` — a BYO bucket YOLO never creates (outside `yolo-*`, so CreateBucket
 * and the hardening writes would AccessDenied; its step skips before reaching
 * this resource).
 *
 * Create-only in both modes — never reconciled, never deleted. Block Public
 * Access and CORS are set once because it holds user data and an app may
 * legitimately change its own CORS or serve public objects. Never YOLO-tagged
 * either: a tag would drag it into `yolo audit` as a permanent "unexpected"
 * finding after `destroy:app` deliberately leaves it standing. Undeletable is
 * backed by the name guard in {@see S3::deleteBucket()} and by the admin tier's
 * destructive S3 grants covering only the regeneratable bucket suffixes.
 */
class S3Bucket implements Resource, Undeletable
{
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
     * Only reached in YOLO-owned mode, where the read tier is granted the namespace
     * — so a 403 means a broken tier and must surface, not be swallowed as "exists".
     */
    public function exists(): bool
    {
        return S3::bucketExists($this->name());
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
    }

    /**
     * Never reconciled, so the tier needs no S3 tag permission on an adopted bucket.
     */
    public function synchroniseTags(bool $apply): array
    {
        return [];
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
