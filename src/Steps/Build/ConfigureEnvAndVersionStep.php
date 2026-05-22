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

class ConfigureEnvAndVersionStep implements Step
{
    public function __construct(
        protected string $environment,
        protected $filesystem = new Filesystem()
    ) {}

    public function __invoke(array $options): void
    {
        $appVersion = Arr::get($options, 'app-version');

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

        // Fargate serves Vite's content-hashed assets straight from public/build,
        // so cache-busting is handled by the filename hashes — no ASSET_URL needed.
        // Only set it when a CDN explicitly fronts the container (manifest `asset-url`),
        // and without a version prefix (the alpha S3/CloudFront versioned-path scheme
        // does not apply to container-served assets).
        if (Manifest::has('asset-url')) {
            $values['ASSET_URL'] = Manifest::get('asset-url');
        }

        $this->filesystem->append(
            Paths::build(".env.$this->environment"),
            $this->generateValues($values)
        );
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
