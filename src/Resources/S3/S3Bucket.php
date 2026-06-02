<?php

namespace Codinglabs\Yolo\Resources\S3;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;

/**
 * The optional application data bucket (AWS_BUCKET) — user-facing storage for
 * the app's own objects. Provisioned only when the manifest defines `bucket`.
 *
 * Block Public Access is applied on create, but never reconciled onto an
 * existing bucket: an app may already serve public objects and flipping BPA
 * under it would break that. SyncS3BucketStep therefore leaves existing buckets
 * untouched (no tag sync either), unlike the create-or-sync Resource default.
 */
class S3Bucket implements Resource
{
    use ResolvesTags;

    public function name(): string
    {
        return Paths::s3AppBucket();
    }

    public function scope(): Scope
    {
        return Scope::App;
    }

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

        // Secure-by-default for new app buckets only — see the class docblock for
        // why this is never reconciled onto an existing bucket.
        Aws::s3()->putPublicAccessBlock([
            'Bucket' => $this->name(),
            'PublicAccessBlockConfiguration' => Aws::publicAccessBlockConfiguration(),
        ]);

        $this->synchroniseTags(apply: true);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseS3Tags($this->name(), $this->tags(), $apply);
    }
}
