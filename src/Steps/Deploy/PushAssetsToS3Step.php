<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Aws\S3\Transfer;
use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Illuminate\Filesystem\Filesystem;
use Codinglabs\Yolo\Resources\Storage\AssetBucket;

/**
 * Uploads the whole `public/` tree to the private asset bucket under
 * `builds/{version}/`, served via CloudFront. The baked ASSET_URL
 * (`{cloudfront}/builds/{version}`) prefixes *every* `asset()` URL, not just
 * Vite's — so all of public/ must reach the CDN: Vite's `build/assets/*` plus
 * static files like `svg/`, `favicon.ico` and `pwa/` icons. Uploading only
 * `public/build` left those 403ing (ORB-blocked in the browser). No public-read
 * ACLs — the bucket is reachable only via the distribution (OAC); the Transfer
 * manager sets per-file Content-Type from the extension.
 */
class PushAssetsToS3Step implements Step
{
    public function __construct(
        protected string $environment,
        protected $filesystem = new Filesystem()
    ) {}

    public function __invoke(): StepResult
    {
        $appVersion = $this->filesystem->get(Paths::version());

        (new Transfer(
            client: Aws::s3(),
            source: Paths::build('public'),
            dest: sprintf('s3://%s/builds/%s', (new AssetBucket())->name(), $appVersion),
        ))->transfer();

        return StepResult::SUCCESS;
    }
}
