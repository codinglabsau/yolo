<?php

namespace Codinglabs\Yolo\Steps\Build;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Contracts\Step;
use Illuminate\Filesystem\Filesystem;

class CreateTemporaryEnvStep implements Step
{
    public function __construct(
        protected string $environment,
        protected        $filesystem = new Filesystem()
    ) {}

    public function __invoke(): void
    {
        // Rename the AWS .env file temporarily to prevent composer and artisan
        // commands using values within commands. This could lead to bad things.
        $this->filesystem->move(
            Paths::build(".env.$this->environment"),
            Paths::build(".env.$this->environment.tmp"),
        );
    }
}
