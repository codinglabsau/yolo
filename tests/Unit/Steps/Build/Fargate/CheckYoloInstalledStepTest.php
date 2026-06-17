<?php

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Build\Fargate\CheckYoloInstalledStep;

/**
 * The guard reads the committed composer.lock and hard-fails when codinglabsau/yolo
 * isn't in the production `packages` set — yolo has to ship inside the runtime image
 * (it backs the burst metrics emitter and the service provider), so a dev-only yolo
 * stripped by `--no-dev` is exactly what this catches. Ungated: it runs for every
 * Fargate build, so there's no skip path to cover.
 *
 * Helper is uniquely named (not the octane test's writeComposerLock) so single-process
 * Pest doesn't hit a redeclare and --parallel doesn't hit an undefined.
 */
function writeYoloComposerLock(array $packages, array $devPackages = []): void
{
    file_put_contents(Paths::base('composer.lock'), json_encode([
        'packages' => array_map(fn (string $name): array => ['name' => $name], $packages),
        'packages-dev' => array_map(fn (string $name): array => ['name' => $name], $devPackages),
    ]));
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => []],
    ]);
});

afterEach(function (): void {
    if (is_file(Paths::base('composer.lock'))) {
        unlink(Paths::base('composer.lock'));
    }
});

it('passes when codinglabsau/yolo is in the production packages', function (): void {
    writeYoloComposerLock(['laravel/framework', 'codinglabsau/yolo']);

    expect((new CheckYoloInstalledStep('testing'))())->toBe(StepResult::SUCCESS);
});

it('hard-fails when codinglabsau/yolo is absent', function (): void {
    writeYoloComposerLock(['laravel/framework', 'laravel/octane']);

    expect(fn (): StepResult => (new CheckYoloInstalledStep('testing'))())
        ->toThrow(RuntimeException::class, 'codinglabsau/yolo is not in composer.lock');
});

it('hard-fails when codinglabsau/yolo is only a dev dependency', function (): void {
    // The footgun: yolo in require-dev passes a composer.json scan but is stripped by
    // the `--no-dev` production install, so the emitter's SDK and the service provider
    // never make it into the running container.
    writeYoloComposerLock(['laravel/framework'], devPackages: ['codinglabsau/yolo']);

    expect(fn (): StepResult => (new CheckYoloInstalledStep('testing'))())
        ->toThrow(RuntimeException::class, 'codinglabsau/yolo is not in composer.lock');
});

it('hard-fails when composer.lock is missing', function (): void {
    // No lock written; can't verify the requirement, so fail closed.
    expect(fn (): StepResult => (new CheckYoloInstalledStep('testing'))())
        ->toThrow(RuntimeException::class, 'composer.lock not found');
});
