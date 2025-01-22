<?php

namespace Codinglabs\Yolo\Steps\Build;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Contracts\Step;
use Illuminate\Filesystem\Filesystem;

class PurgeBuildStep implements Step
{
    public function __construct(
        protected string $environment,
        protected        $filesystem = new Filesystem()
    ) {}

    public function __invoke(): void
    {
        $this->filesystem->deleteDirectory(Paths::yolo());
    }
}
