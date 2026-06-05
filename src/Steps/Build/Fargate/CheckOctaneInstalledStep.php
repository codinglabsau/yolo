<?php

namespace Codinglabs\Yolo\Steps\Build\Fargate;

use RuntimeException;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Illuminate\Filesystem\Filesystem;

/**
 * The web role runs `php artisan octane:start` (see ProcessCommands::octane) — a
 * command that only exists when laravel/octane is installed. Without it the web
 * container crash-loops on boot and the deploy circuit breaker rolls back ~20min
 * later, so this preflight catches it before the image is even built.
 *
 * It reads composer.lock's `packages` array — the production dependency set, i.e.
 * exactly what a `--no-dev` install ships into the image. That's deliberately not
 * a composer.json `require` scan: octane sitting in `require-dev` would pass that
 * check yet be stripped from the runtime image. Reading the lock also makes no
 * assumption about *where* composer install runs — the committed lock is there
 * whether the manifest builds host-side or an app-owned Dockerfile installs
 * vendor itself, so inspecting the build dir's `vendor/` would false-fail the
 * latter.
 *
 * Unlike the sibling SSR runtime check this is a hard fail, not a warn-and-confirm:
 * the lock is authoritative (no heuristic that can false-negative), so a missing
 * octane is a certainty rather than a guess — and a missing web server is fatal,
 * where missing SSR merely degrades to client-side rendering.
 */
class CheckOctaneInstalledStep implements Step
{
    public function __construct(
        protected string $environment,
        protected Filesystem $filesystem = new Filesystem()
    ) {}

    public function __invoke(): StepResult
    {
        // Only the web role launches octane:start; a worker-only app never does.
        if (! Manifest::has('tasks.web')) {
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

        return collect($packages)->contains(fn (array $package) => ($package['name'] ?? null) === 'laravel/octane');
    }
}
