<?php

namespace Codinglabs\Yolo\Steps\Deploy;

use Aws\S3\Transfer;
use Codinglabs\Yolo\Aws;
use Aws\CommandInterface;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Illuminate\Filesystem\Filesystem;
use Codinglabs\Yolo\Resources\S3\AssetBucket;

/**
 * All of public/ goes up, not just Vite's build dir: the baked ASSET_URL
 * prefixes *every* `asset()` URL, so static files (svg/, favicon.ico, pwa/)
 * would otherwise 403.
 */
class PushAssetsToS3Step implements Step
{
    // Every object lives under a per-deploy `builds/{version}/` prefix, so an
    // immutable year-long cache is always safe.
    public const CACHE_CONTROL = 'public, max-age=31536000, immutable';

    public function __construct(
        protected string $environment,
        protected $filesystem = new Filesystem()
    ) {}

    public function __invoke(array $options = []): StepResult
    {
        if (! Manifest::hasWeb()) {
            return StepResult::SKIPPED;
        }

        $appVersion = $this->filesystem->get(Paths::version());
        $public = Paths::build('public');

        (new Transfer(
            client: Aws::s3(),
            source: static::uploadableFiles($public),
            dest: sprintf('s3://%s/builds/%s', (new AssetBucket())->name(), $appVersion),
            // Many small files: latency-bound, so lift the SDK's default concurrency of 5.
            options: ['base_dir' => $public, 'concurrency' => 25, 'before' => static::applyCacheControl(...)],
        ))->transfer();

        return StepResult::SUCCESS;
    }

    public static function applyCacheControl(CommandInterface $command): void
    {
        if (in_array($command->getName(), ['PutObject', 'CreateMultipartUpload'], true)) {
            $command['CacheControl'] = static::CACHE_CONTROL;
        }
    }

    /**
     * Dotfiles/dot-directories (.env, .git/, .htaccess) and source maps have no
     * business on a world-readable origin.
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
