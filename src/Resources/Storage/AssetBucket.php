<?php

namespace Codinglabs\Yolo\Resources\Storage;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Resources\Resource;

/**
 * Dedicated, private S3 bucket holding only the application's public build
 * assets (versioned under builds/{version}/). Block Public Access is fully on
 * — the bucket is reachable only by the CloudFront distribution via Origin
 * Access Control, never directly from the internet. Kept separate from the app
 * data bucket so there is never private data to accidentally expose.
 */
class AssetBucket implements Resource
{
    public function name(): string
    {
        return Helpers::keyedResourceName('assets', exclusive: true);
    }

    public function tags(): array
    {
        return ['Name' => $this->name()];
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

        // Fully private — assets are served only through CloudFront (OAC).
        // OAC bucket policies grant the distribution, not the public, so they
        // coexist with all four Block Public Access settings on.
        Aws::s3()->putPublicAccessBlock([
            'Bucket' => $this->name(),
            'PublicAccessBlockConfiguration' => [
                'BlockPublicAcls' => true,
                'IgnorePublicAcls' => true,
                'BlockPublicPolicy' => true,
                'RestrictPublicBuckets' => true,
            ],
        ]);

        $this->synchroniseTags();
    }

    public function synchroniseTags(): void
    {
        Aws::s3()->putBucketTagging([
            'Bucket' => $this->name(),
            'Tagging' => Aws::tags($this->tags(), wrap: 'TagSet'),
        ]);
    }
}
