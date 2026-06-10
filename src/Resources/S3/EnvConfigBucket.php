<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\S3;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

/**
 * Env-scoped config bucket holding the environment's declaration: the env
 * manifest (`yolo-env.yml`) and the env-shared `.env`. The env-tier sibling of
 * the per-app config buckets — same secrets posture (Block Public Access and
 * versioning reconciled on every sync, no external write principals, no expiry
 * lifecycle). S3 read on this bucket is what gates env-secret control; app
 * deploys never need it.
 */
class EnvConfigBucket implements Resource, SynchronisesConfiguration
{
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
}
