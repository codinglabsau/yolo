<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Resources\S3;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Aws\S3;
use Codinglabs\Yolo\Enums\Scope;
use Codinglabs\Yolo\Resources\Resource;
use Codinglabs\Yolo\Resources\Undeletable;

/**
 * The optional application data bucket (AWS_BUCKET) — user-facing storage for the
 * app's own objects, and the target for direct browser→S3 uploads via a presigned
 * PUT. Provisioned only when the manifest defines `bucket`, in one of two modes:
 *
 * - `bucket: true` — YOLO creates it, naming it in its own keyed namespace
 *   (`yolo-{account}-{env}-{app}-data`), which is globally unique by construction
 *   and inside the `yolo-*` fence the admin tier may write, so the create-time
 *   attributes below can actually be set.
 * - `bucket: some-name` — a bring-your-own bucket that must already exist on this
 *   account (verified before the plan by `Command::ensureAppBucketAdoptable`). YOLO
 *   never creates it: the name is outside `yolo-*`, so CreateBucket and the hardening
 *   writes would AccessDenied. Its step skips before reaching this resource at all.
 *
 * **Create-only in both modes — never reconciled, never deleted.** Block Public
 * Access and the browser-upload CORS ruleset are set ONCE, at create, and then the
 * bucket is left alone forever: it holds user data, and an app may legitimately
 * change its own CORS or start serving public objects. It is deliberately never
 * YOLO-tagged either, so it stays out of the tag-based `yolo audit` — a bucket YOLO
 * hands over at birth isn't one it should keep claiming, and tagging it would leave a
 * permanent "unexpected" finding after `destroy:app` legitimately leaves it standing.
 *
 * Undeletable is the point, not an oversight: `destroy:app` tears down everything
 * else and leaves this bucket alive. Three independent things guarantee it — this
 * interface, the name guard in {@see S3::deleteBucket()}, and the admin tier's
 * destructive S3 grants being scoped to the regeneratable bucket suffixes rather than
 * all of `yolo-*`, so a YOLO-owned data bucket is inside the create fence and outside
 * the delete one.
 */
class S3Bucket implements Resource, Undeletable
{
    public function name(): string
    {
        return Paths::s3AppBucket();
    }

    /**
     * Deliberately untagged in both modes. A `yolo:*` ownership tag would claim a
     * bucket YOLO stops managing the moment it exists, and pull it into the tag-based
     * `yolo audit` — where it would read as debris after a `destroy:app` that
     * deliberately spared it. No tags ⇒ it never appears there.
     */
    public function tags(): array
    {
        return [];
    }

    public function scope(): Scope
    {
        return Scope::App;
    }

    /**
     * Only ever asked in YOLO-owned mode — the adopt path skips before the
     * create-or-sync machinery, so this never probes a bucket the tier may not read. A
     * YOLO-named bucket is inside the namespace the read tier is granted, so a 403
     * here means a genuinely broken tier, not an unowned bucket, and must surface
     * rather than be swallowed as "exists".
     */
    public function exists(): bool
    {
        return S3::bucketExists($this->name());
    }

    public function arn(): string
    {
        return 'arn:aws:s3:::' . $this->name();
    }

    /**
     * Reached only in YOLO-owned mode. Functional attributes are set ONCE, here:
     * Block Public Access is secure-by-default for a freshly-created bucket, and CORS
     * lets the browser→S3 presigned PUT work (permissive origins — the signed URL is
     * the real gate). No YOLO tags are applied (see tags()). Neither attribute is ever
     * reconciled afterwards.
     */
    public function create(): void
    {
        Aws::s3()->createBucket([
            'Bucket' => $this->name(),
        ]);

        Aws::s3()->waitUntil('BucketExists', [
            'Bucket' => $this->name(),
        ]);

        Aws::s3()->putPublicAccessBlock([
            'Bucket' => $this->name(),
            'PublicAccessBlockConfiguration' => Aws::publicAccessBlockConfiguration(),
        ]);

        Aws::s3()->putBucketCors([
            'Bucket' => $this->name(),
            'CORSConfiguration' => ['CORSRules' => [$this->desiredCors()]],
        ]);
    }

    /**
     * Reference-only after create: YOLO never reconciles tags (or any other
     * attribute) onto the app data bucket, so there is never tag drift to report.
     * Returning none keeps the create-or-sync path a clean no-op on an existing
     * bucket — and means the tier needs no S3 permission on an adopted one.
     */
    public function synchroniseTags(bool $apply): array
    {
        return [];
    }

    /**
     * The CORS ruleset stamped at create for direct browser uploads: permissive
     * origins (the signed-storage endpoint is the real gate, not bucket CORS) and the
     * methods a presigned PUT needs. ExposeHeaders is deliberately omitted — add
     * ['ETag'] only when browser multipart uploads are introduced.
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
