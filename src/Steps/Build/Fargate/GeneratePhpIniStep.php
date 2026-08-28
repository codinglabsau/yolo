<?php

namespace Codinglabs\Yolo\Steps\Build\Fargate;

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Illuminate\Filesystem\Filesystem;

/**
 * The base image activates no php.ini, so a container with no app ini runs
 * PHP's compile defaults — 2M uploads, 8M POST bodies, opcache stat'ing an
 * immutable filesystem on every request. Rather than making every app publish
 * a file, the baseline is YOLO's: this step bakes stubs/php.ini.stub into the
 * build context as docker/php.ini, so every app behaves the same by default
 * and baseline improvements arrive with the package. An app that wants its own
 * values publishes docker/php.ini (starting from the stub) — a published copy
 * is the app's to own and ships untouched. Either way the scaffolded
 * Dockerfile COPYs docker/php.ini into conf.d, and CheckPhpIniRuntimeStep
 * proves the built image actually loaded it.
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

        // The app's published copy arrived with CopyApplicationStep — it owns
        // the values, so the baseline stays out of its way.
        if ($this->filesystem->exists($path)) {
            return StepResult::SKIPPED;
        }

        $this->filesystem->ensureDirectoryExists(dirname($path));
        $this->filesystem->copy(Paths::stubs('php.ini.stub'), $path);

        return StepResult::SUCCESS;
    }
}
