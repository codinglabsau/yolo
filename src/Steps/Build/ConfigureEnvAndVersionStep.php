<?php

namespace Codinglabs\Yolo\Steps\Build;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Enums\Iam;
use Codinglabs\Yolo\Contracts\Step;
use Illuminate\Filesystem\Filesystem;
use Codinglabs\Yolo\Resources\Cdn\AssetDistribution;

class ConfigureEnvAndVersionStep implements Step
{
    public function __construct(
        protected string $environment,
        protected $filesystem = new Filesystem()
    ) {}

    public function __invoke(array $options): void
    {
        $appVersion = Arr::get($options, 'app-version');
        $envPath = Paths::build(".env.$this->environment");

        $this->filesystem->put(
            Paths::version(),
            $appVersion
        );

        $values = [
            'APP_VERSION' => $appVersion,
            'AWS_MEDIACONVERT_ROLE_ID' => sprintf(
                'arn:aws:iam::%s:role/%s',
                Aws::accountId(),
                Helpers::keyedResourceName(Iam::MEDIA_CONVERT_ROLE),
            ),
        ];

        // Asset serving:
        //  - assets.cloudfront → assets live in S3 behind the YOLO-provisioned
        //    CloudFront distribution; ASSET_URL points at it, versioned per build.
        //  - asset-url         → an explicitly-configured CDN base (no version prefix).
        //  - neither           → unset; Vite serves /build from the container,
        //    cache-busted by content hashes.
        if (Manifest::get('assets.cloudfront')) {
            $values['ASSET_URL'] = sprintf('https://%s/builds/%s', (new AssetDistribution())->domain(), $appVersion);
        } elseif (Manifest::has('asset-url')) {
            $values['ASSET_URL'] = Manifest::get('asset-url');
        }

        // Inject the app's S3 bucket from the manifest when the consumer hasn't set
        // it explicitly — single source of truth, respects an explicit .env override.
        if (Manifest::has('aws.bucket') && ! $this->envDefines($envPath, 'AWS_BUCKET')) {
            $values['AWS_BUCKET'] = Manifest::get('aws.bucket');
        }

        $this->filesystem->append($envPath, $this->generateValues($values));
    }

    protected function envDefines(string $path, string $key): bool
    {
        if (! $this->filesystem->exists($path)) {
            return false;
        }

        return preg_match('/^' . preg_quote($key, '/') . '=/m', $this->filesystem->get($path)) === 1;
    }

    protected function generateValues(array $values): string
    {
        $result = PHP_EOL . '# YOLO generated values' . PHP_EOL;

        foreach ($values as $key => $value) {
            $result .= "$key=$value" . PHP_EOL;
        }

        return $result;
    }
}
