<?php

namespace Codinglabs\Yolo\Resources\Storage;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

/**
 * Private, app-exclusive bucket holding the application's `.env` files and build
 * artefacts. Because it stores secrets it must never be publicly reachable and
 * its objects must be recoverable, so Block Public Access (all four settings)
 * and versioning are reconciled onto it on every sync — both declarative,
 * idempotent puts. The yolo:app owner tag lets `yolo audit` attribute it.
 */
class S3ArtefactBucket implements Resource, SynchronisesConfiguration
{
    use ResolvesTags;

    public function name(): string
    {
        return Paths::s3ArtefactsBucket();
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

    public function synchroniseConfiguration(): void
    {
        Aws::s3()->putPublicAccessBlock([
            'Bucket' => $this->name(),
            'PublicAccessBlockConfiguration' => [
                'BlockPublicAcls' => true,
                'IgnorePublicAcls' => true,
                'BlockPublicPolicy' => true,
                'RestrictPublicBuckets' => true,
            ],
        ]);

        Aws::s3()->putBucketVersioning([
            'Bucket' => $this->name(),
            'VersioningConfiguration' => ['Status' => 'Enabled'],
        ]);
    }
}
