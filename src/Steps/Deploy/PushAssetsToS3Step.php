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
 * Uploads Vite's `public/build` output to the private asset bucket under
 * `builds/{version}/build`, served via CloudFront. No public-read ACLs — the
 * bucket is reachable only through the distribution (OAC). Path lines up with
 * the baked ASSET_URL (`{cloudfront}/builds/{version}`) + Vite's `/build/assets`
 * references → `{cloudfront}/builds/{version}/build/assets/app-*.js`.
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
            source: Paths::build('public/build'),
            dest: sprintf('s3://%s/builds/%s/build', (new AssetBucket())->name(), $appVersion),
        ))->transfer();

        return StepResult::SUCCESS;
    }
}
