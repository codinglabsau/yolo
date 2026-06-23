<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\S3;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\Scope;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
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
class AssetBucket implements Deletable, Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    public function name(): string
    {
        return Helpers::keyedBucketName('assets');
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
            'PublicAccessBlockConfiguration' => Aws::publicAccessBlockConfiguration(),
        ]);

        $this->synchroniseTags(apply: true);
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseS3Tags($this->name(), $this->tags(), $apply);
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

    /**
     * Empty then delete the bucket. S3 refuses DeleteBucket on a non-empty
     * bucket, so every object is removed first (paginated list, batched
     * deletes). This bucket is not versioned (create() enables no versioning),
     * so the current-object sweep is sufficient — there are no prior versions
     * or delete markers to clear. A concurrent removal (NoSuchBucket / 404) is
     * tolerated.
     */
    public function delete(): void
    {
        try {
            $this->emptyObjects();

            S3::deleteBucket($this->name());
        } catch (S3Exception $e) {
            if (S3::isNotFound($e)) {
                return;
            }

            throw $e;
        }
    }

    /**
     * Delete every object in the bucket, paginating the listing and batching
     * deletes up to S3's per-request limit of 1000 keys.
     */
    protected function emptyObjects(): void
    {
        $continuationToken = null;

        do {
            $page = Aws::s3()->listObjectsV2(array_filter([
                'Bucket' => $this->name(),
                'ContinuationToken' => $continuationToken,
            ]));

            $objects = collect($page['Contents'] ?? [])
                ->map(fn (array $object): array => ['Key' => $object['Key']])
                ->all();

            if ($objects !== []) {
                Aws::s3()->deleteObjects([
                    'Bucket' => $this->name(),
                    'Delete' => ['Objects' => $objects],
                ]);
            }

            $continuationToken = $page['IsTruncated'] ? $page['NextContinuationToken'] : null;
        } while ($continuationToken !== null);
    }
}
