<?php

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Build\Fargate\CheckCloudWatchSdkStep;

/**
 * The guard reads the committed composer.lock and hard-fails when aws/aws-sdk-php
 * isn't in the production `packages` set — the burst saturation emitter publishes
 * via PutMetricData and `require`s it, so a burst-enabled app without it would
 * crash-loop the emitter and leave burst silently dark. Only runs for an autoscaling
 * Octane web tier (the gate burst keys off).
 *
 * Helper is uniquely named (not the octane test's writeComposerLock) so single-process
 * Pest doesn't hit a redeclare and --parallel doesn't hit an undefined.
 */
function writeSdkComposerLock(array $packages, array $devPackages = []): void
{
    file_put_contents(Paths::base('composer.lock'), json_encode([
        'packages' => array_map(fn (string $name): array => ['name' => $name], $packages),
        'packages-dev' => array_map(fn (string $name): array => ['name' => $name], $devPackages),
    ]));
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => true]],
    ]);
});

afterEach(function (): void {
    if (is_file(Paths::base('composer.lock'))) {
        unlink(Paths::base('composer.lock'));
    }
});

it('skips without reading composer.lock when web autoscaling is off', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => []],
    ]);

    // No composer.lock on disk — proof the step short-circuits before touching it.
    expect((new CheckCloudWatchSdkStep('testing'))())->toBe(StepResult::SKIPPED);
});

it('skips in classic mode (no worker metrics, so no emitter to need the SDK)', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['octane' => false, 'autoscaling' => true]],
    ]);

    expect((new CheckCloudWatchSdkStep('testing'))())->toBe(StepResult::SKIPPED);
});

it('passes when aws/aws-sdk-php is in the production packages', function (): void {
    writeSdkComposerLock(['laravel/framework', 'aws/aws-sdk-php']);

    expect((new CheckCloudWatchSdkStep('testing'))())->toBe(StepResult::SUCCESS);
});

it('hard-fails when aws/aws-sdk-php is absent', function (): void {
    writeSdkComposerLock(['laravel/framework', 'laravel/octane']);

    expect(fn (): StepResult => (new CheckCloudWatchSdkStep('testing'))())
        ->toThrow(RuntimeException::class, 'aws/aws-sdk-php is not in composer.lock');
});

it('hard-fails when aws/aws-sdk-php is only a dev dependency', function (): void {
    // The footgun: an SDK in require-dev passes a composer.json scan but is stripped
    // by the `--no-dev` production install, so the emitter fatals on boot.
    writeSdkComposerLock(['laravel/framework'], devPackages: ['aws/aws-sdk-php']);

    expect(fn (): StepResult => (new CheckCloudWatchSdkStep('testing'))())
        ->toThrow(RuntimeException::class, 'aws/aws-sdk-php is not in composer.lock');
});

it('hard-fails when composer.lock is missing', function (): void {
    expect(fn (): StepResult => (new CheckCloudWatchSdkStep('testing'))())
        ->toThrow(RuntimeException::class, 'composer.lock not found');
});
