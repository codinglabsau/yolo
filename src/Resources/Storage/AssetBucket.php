<?php

namespace Codinglabs\Yolo\Resources\Storage;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

/**
 * Dedicated, private S3 bucket holding only the application's public build
 * assets (versioned under builds/{version}/). Block Public Access is fully on
 * — the bucket is reachable only by the CloudFront distribution via Origin
 * Access Control, never directly from the internet. Kept separate from the app
 * data bucket so there is never private data to accidentally expose.
 *
 * The bucket carries its own CORS configuration so it returns
 * Access-Control-Allow-Origin itself. CloudFront forwards the viewer Origin
 * header (the CORS-S3Origin origin-request policy) and serves S3's CORS header
 * as a normal cached response header — present on every request path, including
 * the revalidation CloudFront forces on `Cache-Control: no-cache` / `max-age=0`
 * (reloads, DevTools "Disable cache"), where a response-headers-policy CORS
 * header is silently dropped.
 */
class AssetBucket implements Resource, SynchronisesConfiguration
{
    public function name(): string
    {
        return Helpers::keyedResourceName('assets', exclusive: true);
    }

    public function tags(): array
    {
        return ['Name' => $this->name(), 'yolo:app' => Manifest::name()];
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
        $this->synchroniseConfiguration();
    }

    public function synchroniseTags(): void
    {
        Aws::s3()->putBucketTagging([
            'Bucket' => $this->name(),
            'Tagging' => Aws::tags($this->tags(), wrap: 'TagSet'),
        ]);
    }

    /**
     * Allow any origin to read the assets (GET/HEAD). They're public,
     * content-hashed build files behind CloudFront — `*` is correct and keeps
     * the cached response origin-agnostic. Idempotent: a re-sync re-puts the
     * same rule, so existing buckets pick it up without a re-create.
     */
    public function synchroniseConfiguration(): void
    {
        Aws::s3()->putBucketCors([
            'Bucket' => $this->name(),
            'CORSConfiguration' => [
                'CORSRules' => [
                    [
                        'AllowedMethods' => ['GET', 'HEAD'],
                        'AllowedOrigins' => ['*'],
                        'AllowedHeaders' => ['*'],
                        'MaxAgeSeconds' => 86400,
                    ],
                ],
            ],
        ]);
    }
}
