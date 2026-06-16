<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\S3;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Enums\Scope;
use Aws\S3\Exception\S3Exception;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\ResolvesTags;

/**
 * The optional application data bucket (AWS_BUCKET) — user-facing storage for the
 * app's own objects, and the target for direct browser→S3 uploads via a presigned
 * PUT. Provisioned only when the manifest defines `bucket`.
 *
 * **Create-only — never reconciled.** The bucket name is whatever the manifest
 * says (typically a custom, pre-existing bucket brought into the app), so YOLO
 * sets its attributes (Block Public Access, CORS for the cross-origin upload PUT,
 * tags) ONCE at create time and then leaves it completely alone. An app may
 * already serve public objects or own its own CORS, and a brought-in bucket isn't
 * YOLO's to reconcile — so there is no post-create sync of any attribute.
 *
 * This is also why a 403 on the existence check reads as "exists, hands-off"
 * (see exists()): a custom-named bucket is outside the `yolo-*` scope the tier
 * policies grant, so the capped admin can't (and shouldn't) read it — and since
 * we only ever create when the bucket is truly absent, treating 403 as present is
 * exactly right. The upshot: YOLO needs no S3 permission on a bucket it doesn't own.
 */
class S3Bucket implements Resource
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
        try {
            return S3::bucketExists($this->name());
        } catch (S3Exception $exception) {
            // 403 = the bucket exists but isn't ours to inspect (a brought-in
            // external bucket, and/or the capped tier with no perms on a non-yolo
            // name). Treat as "exists, hands-off" so the create-only step leaves
            // it alone rather than hard-failing — we only ever create when the
            // bucket is genuinely absent (which surfaces as a 404, not a 403).
            if ((int) $exception->getStatusCode() === 403) {
                return true;
            }

            throw $exception;
        }
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

        // Attributes are set ONCE, here. Block Public Access is secure-by-default
        // for a freshly-created bucket; CORS lets the browser→S3 presigned PUT work
        // without Vapor (permissive origins — the signed URL is the real gate);
        // tags let `yolo audit` attribute the bucket. None of these is ever
        // reconciled afterwards.
        Aws::s3()->putPublicAccessBlock([
            'Bucket' => $this->name(),
            'PublicAccessBlockConfiguration' => Aws::publicAccessBlockConfiguration(),
        ]);

        Aws::synchroniseS3Tags($this->name(), $this->tags(), apply: true);

        Aws::s3()->putBucketCors([
            'Bucket' => $this->name(),
            'CORSConfiguration' => ['CORSRules' => [$this->desiredCors()]],
        ]);
    }

    /**
     * Reference-only after create: YOLO never reconciles tags (or any other
     * attribute) onto an existing app data bucket, so there is never tag drift to
     * report. Returning none keeps the create-or-sync path a clean no-op on an
     * existing bucket — and means the tier needs no S3 permission on it.
     */
    public function synchroniseTags(bool $apply): array
    {
        return [];
    }

    /**
     * The managed CORS ruleset stamped at create for direct browser uploads:
     * permissive origins (the signed-storage endpoint is the real gate, not bucket
     * CORS) and the methods a presigned PUT needs. ExposeHeaders is deliberately
     * omitted — add ['ETag'] only when browser multipart uploads are introduced.
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
