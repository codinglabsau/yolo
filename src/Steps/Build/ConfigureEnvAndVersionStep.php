<?php

namespace Codinglabs\Yolo\Steps\Build;

use Codinglabs\Yolo\Aws;
use Codinglabs\Yolo\Paths;
use Illuminate\Support\Arr;
use Codinglabs\Yolo\Helpers;
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

        $this->filesystem->append(
            Paths::build(".env.$this->environment"),
            $this->generateValues([
                'APP_VERSION' => $appVersion,
                'ASSET_URL' => Paths::assetUrl($appVersion),
                'AWS_MEDIACONVERT_ROLE_ID' => sprintf(
                    'arn:aws:iam::%s:role/%s',
                    Aws::accountId(),
                    Helpers::keyedResourceName(Iam::MEDIA_CONVERT_ROLE),
                ),
            ])
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
