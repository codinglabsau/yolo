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
 * Holds only the app's public build assets, reachable solely through CloudFront
 * (OAC) — kept apart from the data bucket so there is never private data to
 * expose. Deliberately NO CORS config: the distribution's response-headers
 * policy owns CORS and the viewer Origin isn't forwarded to S3, so a bucket rule
 * would be dead weight — and if Origin forwarding were ever reintroduced, S3
 * would emit a second Access-Control-Allow-Origin and browsers reject duplicates.
 * Sync removes any CORS config it finds.
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

        // OAC policies grant the distribution, not the public, so they coexist with full Block Public Access.
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
     * Not versioned, so a current-object sweep is enough before DeleteBucket
     * (S3 refuses on a non-empty bucket).
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
