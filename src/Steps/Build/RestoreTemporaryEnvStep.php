<?php

namespace Codinglabs\Yolo\Steps\Build;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Illuminate\Filesystem\Filesystem;

class RestoreTemporaryEnvStep implements Step
{
    public function __construct(
        protected string $environment,
        protected $filesystem = new Filesystem()
    ) {}

    public function __invoke(array $options = []): StepResult
    {
        // Once the build hooks have run, move the .env into its final
        // place so the Docker build bakes it into the image as /app/.env.
        $this->filesystem->move(
            Paths::build(".env.$this->environment.tmp"),
            Paths::build('.env'),
        );

        return StepResult::SUCCESS;
    }
}
