<?php

namespace Codinglabs\Yolo\Steps\Build\Fargate;

use RuntimeException;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Illuminate\Filesystem\Filesystem;

/**
 * Without octane the web container crash-loops and the circuit breaker only
 * rolls back ~20min later. Reads composer.lock's `packages` (what `--no-dev`
 * ships) rather than composer.json — octane in `require-dev` would pass a
 * require scan yet be stripped — and not the build dir's `vendor/`, which an
 * app-owned Dockerfile may install itself. Hard fail (not warn-and-confirm like
 * SSR) because the lock is authoritative and a missing web server is fatal.
 */
class CheckOctaneInstalledStep implements Step
{
    public function __construct(
        protected string $environment,
        protected Filesystem $filesystem = new Filesystem()
    ) {}

    public function __invoke(array $options = []): StepResult
    {
        if (! Manifest::hasWeb() || ! Manifest::usesOctane()) {
            return StepResult::SKIPPED;
        }

        $lock = Paths::base('composer.lock');

        if (! $this->filesystem->exists($lock)) {
            throw new RuntimeException(
                'Build aborted: composer.lock not found, so laravel/octane can\'t be verified. '
                . 'Run `composer install` and commit composer.lock before deploying.'
            );
        }

        if ($this->requiresOctane($this->filesystem->get($lock))) {
            return StepResult::SUCCESS;
        }

        throw new RuntimeException(
            'Build aborted: laravel/octane is not in composer.lock\'s production requirements. '
            . 'The web container runs `octane:start` and will crash-loop without it. Run '
            . '`composer require laravel/octane` (production, not --dev — the image installs '
            . 'with --no-dev) and commit the updated composer.lock.'
        );
    }

    protected function requiresOctane(string $lock): bool
    {
        $packages = json_decode($lock, true)['packages'] ?? [];

        return collect($packages)->contains(fn (array $package): bool => ($package['name'] ?? null) === 'laravel/octane');
    }
}
