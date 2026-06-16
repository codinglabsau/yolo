<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Aws\S3\Transfer;
use Codinglabs\Yolo\Aws;
use Aws\CommandInterface;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Illuminate\Filesystem\Filesystem;
use Codinglabs\Yolo\Resources\S3\AssetBucket;

/**
 * Uploads the `public/` tree (minus dotfiles and source maps — see
 * uploadableFiles) to the private asset bucket under `builds/{version}/`, served
 * via CloudFront. The baked ASSET_URL (`{cloudfront}/builds/{version}`) prefixes
 * *every* `asset()` URL, not just Vite's — so all of public/ must reach the CDN:
 * Vite's `build/assets/*` plus static files like `svg/`, `favicon.ico` and `pwa/`
 * icons. Uploading only `public/build` left those 403ing (ORB-blocked in the
 * browser). No public-read ACLs — the bucket is reachable only via the
 * distribution (OAC); the Transfer manager sets per-file Content-Type from the
 * extension, and `applyCacheControl` stamps the immutable Cache-Control.
 */
class PushAssetsToS3Step implements Step
{
    // Objects live under a per-deploy `builds/{version}/` prefix (ASSET_URL
    // carries the version), so every upload is a brand-new immutable URL — a
    // year-long immutable cache-control is always safe and keeps the CDN + the
    // browser hot, shrinking the cold-miss window the distribution has to ride.
    public const CACHE_CONTROL = 'public, max-age=31536000, immutable';

    public function __construct(
        protected string $environment,
        protected $filesystem = new Filesystem()
    ) {}

    public function __invoke(array $options = []): StepResult
    {
        $appVersion = $this->filesystem->get(Paths::version());
        $public = Paths::build('public');

        (new Transfer(
            client: Aws::s3(),
            source: static::uploadableFiles($public),
            dest: sprintf('s3://%s/builds/%s', (new AssetBucket())->name(), $appVersion),
            // The asset tree is many small files (Vite chunks, icons, svgs), so
            // it's latency-bound, not bandwidth-bound — lift the upload concurrency
            // well above the SDK's conservative default of 5 to shrink the push.
            options: ['base_dir' => $public, 'concurrency' => 25, 'before' => static::applyCacheControl(...)],
        ))->transfer();

        return StepResult::SUCCESS;
    }

    /**
     * Transfer `before` hook: stamp the immutable Cache-Control onto each object
     * as it's uploaded. Guarded to the upload commands so it's a no-op if the
     * Transfer manager ever issues anything else.
     */
    public static function applyCacheControl(CommandInterface $command): void
    {
        if (in_array($command->getName(), ['PutObject', 'CreateMultipartUpload'], true)) {
            $command['CacheControl'] = static::CACHE_CONTROL;
        }
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
            $segments = explode('/', substr((string) $path, strlen($root) + 1));

            if (collect($segments)->contains(fn (string $segment): bool => str_starts_with($segment, '.'))) {
                continue;
            }

            if (str_ends_with((string) $path, '.map')) {
                continue;
            }

            yield $path;
        }
    }
}
