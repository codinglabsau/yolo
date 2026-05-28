<?php

namespace Codinglabs\Yolo\Resources\S3;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

/**
 * Dedicated, private S3 bucket holding only the application's public build
 * assets (versioned under builds/{version}/). Block Public Access is fully on
 * — the bucket is reachable only by the CloudFront distribution via Origin
 * Access Control, never directly from the internet. Kept separate from the app
 * data bucket so there is never private data to accidentally expose.
 *
 * The bucket deliberately carries NO CORS configuration. CORS for the
 * cross-origin module imports is owned entirely by the distribution's
 * response-headers policy (a static Access-Control-Allow-Origin: * on every
 * response); the viewer Origin header is not forwarded to S3, so the bucket
 * never needs its own rules. A CORS config here would be dead weight and a live
 * foot-gun — if Origin forwarding were ever reintroduced, S3 would emit a second
 * Access-Control-Allow-Origin and browsers reject duplicate headers. Sync
 * enforces the absence: any CORS config found on the bucket is removed.
 */
class AssetBucket implements Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    public function name(): string
    {
        return $this->keyedName('assets');
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

    /**
     * Enforce that the bucket carries no CORS configuration — CORS is owned by
     * the distribution's response-headers policy. Checks the live config first so
     * a clean sync writes nothing and a dry-run reports the removal; returns the
     * drift as Change[].
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        if (S3::bucketCors($this->name()) === null) {
            return [];
        }

        if ($apply) {
            Aws::s3()->deleteBucketCors(['Bucket' => $this->name()]);
        }

        return [Change::make('cors', 'present', 'removed (owned by the distribution)')];
    }
}
