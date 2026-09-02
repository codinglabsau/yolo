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
 * Holds the env manifest and the env-shared `.env`. Read access on this bucket
 * is what gates env-secret control — app deploys never need it.
 */
class EnvConfigBucket implements Deletable, Resource, SynchronisesConfiguration
{
    use EmptiesBucket;
    use ReconcilesBucketHardening;
    use ResolvesTags;

    public function name(): string
    {
        return Paths::s3EnvConfigBucket();
    }

    public function scope(): Scope
    {
        return Scope::Env;
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

    public function synchroniseConfiguration(bool $apply = true): array
    {
        return [
            ...$this->reconcilePublicAccessBlock($apply),
            ...$this->reconcileVersioning($apply),
        ];
    }

    public function delete(): void
    {
        try {
            $this->emptyVersions();

            S3::deleteBucket($this->name());
        } catch (S3Exception $e) {
            if (S3::isNotFound($e)) {
                return;
            }

            throw $e;
        }
    }
}
