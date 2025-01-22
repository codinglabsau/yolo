<?php

namespace Codinglabs\Yolo\Steps\Build;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Contracts\Step;
use Illuminate\Filesystem\Filesystem;

class RestoreTemporaryEnvStep implements Step
{
    public function __construct(
        protected string $environment,
        protected $filesystem = new Filesystem()
    ) {}

    public function __invoke(): void
    {
        // Once the build process is complete, move the .env into it's
        // final place to be added to the build artefact for deploy.
        $this->filesystem->move(
            Paths::build(".env.$this->environment.tmp"),
            Paths::build(".env"),
        );
    }
}
