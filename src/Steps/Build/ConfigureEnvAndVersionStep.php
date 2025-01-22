<?php

namespace Codinglabs\Yolo\Steps\Build;

use Codinglabs\Yolo\Paths;
use Illuminate\Support\Arr;
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
            PHP_EOL .
            'APP_VERSION=' . $appVersion . PHP_EOL .
            'ASSET_URL=' . Paths::cloudfront($appVersion) . PHP_EOL
        );
    }
}
