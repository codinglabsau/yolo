<?php

namespace Codinglabs\Yolo\Steps\Build\Fargate;

use RuntimeException;
use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Manifest;
use Codinglabs\Yolo\Contracts\Step;
use Codinglabs\Yolo\Enums\StepResult;
use Illuminate\Filesystem\Filesystem;

/**
 * Burst autoscaling's saturation emitter publishes its metric in real time with
 * `PutMetricData` (see GenerateSupervisorConfigStep / the yolo-saturation stub),
 * which `require`s the app's `aws/aws-sdk-php`. Most Fargate apps already ship it
 * transitively (S3, SQS), but a burst-enabled app that touches neither would not —
 * and the emitter would fatal on boot, supervisord would crash-loop it, and burst
 * would be silently dark on the target-tracking fallback. This preflight catches
 * that before the image is built.
 *
 * Like {@see CheckOctaneInstalledStep} it reads composer.lock's `packages` array —
 * the `--no-dev` production set the image actually ships — not composer.json, so an
 * SDK sitting only in `require-dev` can't pass this yet be stripped at build. Only
 * runs for an autoscaling Octane web tier (the single gate burst keys off); every
 * other app never builds the emitter, so the SDK is none of its concern.
 */
class CheckCloudWatchSdkStep implements Step
{
    public function __construct(
        protected string $environment,
        protected Filesystem $filesystem = new Filesystem()
    ) {}

    public function __invoke(array $options = []): StepResult
    {
        if (! Manifest::usesMetricsCaddyfile()) {
            return StepResult::SKIPPED;
        }

        $lock = Paths::base('composer.lock');

        if (! $this->filesystem->exists($lock)) {
            throw new RuntimeException(
                'Build aborted: composer.lock not found, so aws/aws-sdk-php can\'t be verified. '
                . 'Run `composer install` and commit composer.lock before deploying.'
            );
        }

        if ($this->requiresSdk((string) $this->filesystem->get($lock))) {
            return StepResult::SUCCESS;
        }

        throw new RuntimeException(
            'Build aborted: web autoscaling is on but aws/aws-sdk-php is not in composer.lock\'s '
            . 'production requirements. The burst saturation emitter publishes its metric via '
            . 'PutMetricData and needs it. Run `composer require aws/aws-sdk-php` (production, '
            . 'not --dev — the image installs with --no-dev) and commit composer.lock, or turn '
            . 'web autoscaling off.'
        );
    }

    protected function requiresSdk(string $lock): bool
    {
        $packages = json_decode($lock, true)['packages'] ?? [];

        return collect($packages)->contains(fn (array $package): bool => ($package['name'] ?? null) === 'aws/aws-sdk-php');
    }
}
