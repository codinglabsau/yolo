<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\S3;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Enums\Scope;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Deletable;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

/**
 * Private, app-exclusive bucket holding the application's `.env.{env}` files.
 * Because it stores secrets it must never be publicly reachable and its
 * objects must be recoverable, so Block Public Access (all four settings)
 * and versioning are reconciled onto it on every sync — both declarative,
 * idempotent puts. The yolo:app owner tag lets `yolo audit` attribute it.
 */
class S3ConfigBucket implements Deletable, Resource, SynchronisesConfiguration
{
    use EmptiesBucket;
    use ReconcilesBucketHardening;
    use ResolvesTags;

    public function name(): string
    {
        return Paths::s3ConfigBucket();
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

        $this->synchroniseTags(apply: true);
        $this->synchroniseConfiguration();
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseS3Tags($this->name(), $this->tags(), $apply);
    }

    /**
     * Reconcile Block Public Access and versioning, each read-compared-then-
     * written so a clean sync is a no-op and a dry-run reports exactly what
     * would change. Returns the drifted attributes as Change[].
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        return [
            ...$this->reconcilePublicAccessBlock($apply),
            ...$this->reconcileVersioning($apply),
        ];
    }

    /**
     * Empty then delete the bucket. S3 refuses DeleteBucket on a non-empty
     * bucket. This bucket is versioned (create() reconciles versioning to
     * Enabled), so emptying must clear every object version AND every delete
     * marker — a plain object sweep would leave noncurrent versions behind and
     * the delete would fail. A concurrent removal (NoSuchBucket / 404) is
     * tolerated.
     */
    public function delete(): void
    {
        try {
            $this->emptyVersions();

            Aws::s3()->deleteBucket(['Bucket' => $this->name()]);
        } catch (S3Exception $e) {
            if (S3::isNotFound($e)) {
                return;
            }

            throw $e;
        }
    }
}
