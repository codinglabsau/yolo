<?php

namespace Codinglabs\Yolo\Steps\Build\Fargate;

use RuntimeException;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Illuminate\Filesystem\Filesystem;

/**
 * YOLO's service provider must boot inside the image (burst metrics reporter,
 * runtime API), so it has to survive `--no-dev`. A prod-required yolo also
 * guarantees `aws/aws-sdk-php` transitively — that's why there's no separate
 * SDK preflight. Reads composer.lock's `packages` for the reasons given on
 * {@see CheckOctaneInstalledStep}. Ungated and first so a misconfigured app
 * fails before composer install and asset compilation.
 */
class CheckYoloInstalledStep implements Step
{
    public function __construct(
        protected string $environment,
        protected Filesystem $filesystem = new Filesystem()
    ) {}

    public function __invoke(array $options = []): StepResult
    {
        $lock = Paths::base('composer.lock');

        if (! $this->filesystem->exists($lock)) {
            throw new RuntimeException(
                'Build aborted: composer.lock not found, so codinglabsau/yolo can\'t be verified. '
                . 'Run `composer install` and commit composer.lock before deploying.'
            );
        }

        if ($this->requiresYolo((string) $this->filesystem->get($lock))) {
            return StepResult::SUCCESS;
        }

        throw new RuntimeException(
            'Build aborted: codinglabsau/yolo is not in composer.lock\'s production requirements. '
            . 'YOLO must ship in the runtime image (its service provider backs the burst metrics '
            . 'reporter and the runtime API), so it has to be a production dependency, not require-dev — the '
            . 'image installs with --no-dev. Run `composer require codinglabsau/yolo` and commit '
            . 'composer.lock.'
        );
    }

    protected function requiresYolo(string $lock): bool
    {
        $packages = json_decode($lock, true)['packages'] ?? [];

        return collect($packages)->contains(fn (array $package): bool => ($package['name'] ?? null) === 'codinglabsau/yolo');
    }
}
