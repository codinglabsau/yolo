<?php

namespace Codinglabs\Yolo\Steps\Build;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Illuminate\Filesystem\Filesystem;

class CreateTemporaryEnvStep implements Step
{
    public function __construct(
        protected string $environment,
        protected $filesystem = new Filesystem()
    ) {}

    public function __invoke(array $options = []): StepResult
    {
        // Hidden while the build hooks run so composer/artisan can't pick up the
        // deployed environment's values.
        $this->filesystem->move(
            Paths::build(".env.$this->environment"),
            Paths::build(".env.$this->environment.tmp"),
        );

        return StepResult::SUCCESS;
    }
}
