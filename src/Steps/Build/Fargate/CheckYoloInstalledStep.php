<?php

namespace Codinglabs\Yolo\Steps\Build\Fargate;

use RuntimeException;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Illuminate\Filesystem\Filesystem;

/**
 * YOLO must ship inside the runtime image as a production dependency, not just sit
 * on the deploy runner as a dev tool. Its Laravel service provider is auto-discovered
 * and boots with the app:
 *
 * - on the autoscaling web tier it publishes FrankenPHP worker saturation for burst
 *   scaling via `PutMetricData` from an after-response hook — yolo depends on
 *   `aws/aws-sdk-php`, so a prod-required yolo guarantees the SDK transitively (this is
 *   why there's no separate SDK preflight);
 * - the same provider backs yolo's runtime API — a facade abstracting the AWS work
 *   (e.g. adding/removing WAF IP-set entries) rather than shelling out to the CLI.
 *
 * A dev-only package is stripped by `--no-dev`, so the provider would never load.
 *
 * The CLI itself is inert in the container — nothing runs `yolo` there; the package
 * earns its place purely as a bootable runtime library. Like
 * {@see CheckOctaneInstalledStep} this reads composer.lock's `packages` array — the
 * `--no-dev` production set the image actually ships — not composer.json, so a yolo
 * sitting only in `require-dev` is correctly caught: it would pass a `require` scan
 * yet be stripped from the runtime image. Reading the committed lock also makes no
 * assumption about where `composer install` runs.
 *
 * Ungated and run first, before any build effort: every Fargate app the manifest
 * declares tasks for must carry yolo at runtime, so a misconfigured app fails
 * immediately rather than after composer install and asset compilation.
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
