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
 * Uploads the `public/` tree (minus dotfiles and source maps — see
 * uploadableFiles) to the private asset bucket under `builds/{version}/`, served
 * via CloudFront. The baked ASSET_URL (`{cloudfront}/builds/{version}`) prefixes
 * *every* `asset()` URL, not just Vite's — so all of public/ must reach the CDN:
 * Vite's `build/assets/*` plus static files like `svg/`, `favicon.ico` and `pwa/`
 * icons. Uploading only `public/build` left those 403ing (ORB-blocked in the
 * browser). No public-read ACLs — the bucket is reachable only via the
 * distribution (OAC); the Transfer manager sets per-file Content-Type from the
 * extension.
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
        $public = Paths::build('public');

        (new Transfer(
            client: Aws::s3(),
            source: static::uploadableFiles($public),
            dest: sprintf('s3://%s/builds/%s', (new AssetBucket())->name(), $appVersion),
            options: ['base_dir' => $public],
        ))->transfer();

        return StepResult::SUCCESS;
    }

    /**
     * Everything under public/ is fair game for the CDN *except* things that have
     * no business on a world-readable origin: dotfiles and anything inside a
     * dot-directory (.env, .git/, .htaccess, .DS_Store) and JS/CSS source maps
     * (which hand out the original source). Yields absolute path strings — the
     * shape Transfer's iterator source expects — and base_dir is stripped from
     * each to form the S3 key.
     *
     * @return \Generator<string>
     */
    public static function uploadableFiles(string $root): \Generator
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME)
        );

        foreach ($files as $path) {
            $segments = explode('/', substr($path, strlen($root) + 1));

            if (collect($segments)->contains(fn (string $segment) => str_starts_with($segment, '.'))) {
                continue;
            }

            if (str_ends_with($path, '.map')) {
                continue;
            }

            yield $path;
        }
    }
}
