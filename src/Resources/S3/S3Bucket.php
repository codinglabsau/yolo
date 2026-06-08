<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\S3;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;
use Codinglabs\Yolo\Resources\SynchronisesConfiguration;

/**
 * The optional application data bucket (AWS_BUCKET) — user-facing storage for
 * the app's own objects, and the target for direct browser→S3 uploads via a
 * presigned PUT. Provisioned only when the manifest defines `bucket`.
 *
 * Block Public Access is applied on create but NEVER reconciled onto an existing
 * bucket: an app may already serve public objects and flipping BPA under it would
 * break that. CORS and tags, by contrast, ARE reconciled on every sync (the
 * standard create-or-sync Resource path) — CORS so the cross-origin upload PUT
 * keeps working without Vapor, tags so `yolo audit` can attribute the bucket.
 * Owning CORS is safe: it grants no data access (the presigned URL / signed-
 * storage endpoint is the gate), and the permissive ruleset matches what existing
 * (incl. Vapor-adopted) buckets already carry, so the first sync is a near no-op.
 */
class S3Bucket implements Resource, SynchronisesConfiguration
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

        $this->synchroniseConfiguration();
    }

    public function synchroniseTags(bool $apply): array
    {
        return Aws::synchroniseS3Tags($this->name(), $this->tags(), $apply);
    }

    /**
     * Reconcile the bucket's CORS to the managed ruleset so the browser→S3
     * presigned-PUT upload path works without Vapor. Read-compared-then-written
     * so a clean sync writes nothing and a dry-run reports the drift; returns it
     * as Change[]. Block Public Access is deliberately not reconciled here (it's
     * create-only — see the class docblock); tags ride synchroniseTags().
     */
    public function synchroniseConfiguration(bool $apply = true): array
    {
        $desired = [$this->desiredCors()];
        $current = S3::bucketCors($this->name());

        if (Helpers::documentsEqual($current, $desired)) {
            return [];
        }

        if ($apply) {
            Aws::s3()->putBucketCors([
                'Bucket' => $this->name(),
                'CORSConfiguration' => ['CORSRules' => $desired],
            ]);
        }

        return [Change::make('cors', $current === null ? null : 'present', 'managed (origins *, GET/PUT/HEAD)')];
    }

    /**
     * The managed CORS ruleset for direct browser uploads: permissive origins
     * (the signed-storage endpoint is the real gate, not bucket CORS) and the
     * methods a presigned PUT needs. ExposeHeaders is deliberately omitted — AWS
     * drops empty optional fields on read, so an empty value would read back
     * absent and drift forever; add ['ETag'] only when browser multipart uploads
     * are introduced.
     *
     * @return array<string, mixed>
     */
    protected function desiredCors(): array
    {
        return [
            'AllowedOrigins' => ['*'],
            'AllowedMethods' => ['GET', 'PUT', 'HEAD'],
            'AllowedHeaders' => ['*'],
            'MaxAgeSeconds' => 3600,
        ];
    }
}
