<?php

namespace Codinglabs\Yolo\Steps\Build\Fargate;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Illuminate\Filesystem\Filesystem;

/**
 * The base image activates no php.ini, so without this a container runs PHP's
 * compile defaults (2M uploads, 8M POST bodies, opcache stat'ing an immutable
 * filesystem). An app that publishes its own docker/php.ini owns it and it
 * ships untouched; CheckPhpIniRuntimeStep proves the image actually loaded it.
 */
class GeneratePhpIniStep implements Step
{
    public function __construct(
        protected string $environment,
        protected $filesystem = new Filesystem()
    ) {}

    public function __invoke(array $options = []): StepResult
    {
        $path = Paths::build('docker/php.ini');

        if ($this->filesystem->exists($path)) {
            return StepResult::SKIPPED;
        }

        $this->filesystem->ensureDirectoryExists(dirname($path));
        $this->filesystem->copy(Paths::stubs('php.ini.stub'), $path);

        return StepResult::SUCCESS;
    }
}
