<?php

use Codinglabs\Yolo\Paths;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Build\Fargate\CheckOctaneInstalledStep;

/**
 * The guard reads the committed composer.lock and hard-fails when laravel/octane
 * isn't in the production `packages` set — exactly what a `--no-dev` install ships
 * into the image, and what the web role's `octane:start` needs to boot.
 */
function writeComposerLock(array $packages, array $devPackages = []): void
{
    file_put_contents(Paths::base('composer.lock'), json_encode([
        'packages' => array_map(fn (string $name): array => ['name' => $name], $packages),
        'packages-dev' => array_map(fn (string $name): array => ['name' => $name], $devPackages),
    ]));
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true],
    ]);
});

afterEach(function (): void {
    if (is_file(Paths::base('composer.lock'))) {
        unlink(Paths::base('composer.lock'));
    }
});

it('skips without reading composer.lock for a worker-only app', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['queue' => true],
    ]);

    // No composer.lock on disk — proof the step short-circuits before touching it.
    expect((new CheckOctaneInstalledStep('testing'))())->toBe(StepResult::SKIPPED);
});

it('skips when tasks.web.octane is false (classic mode needs no octane package)', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['octane' => false]],
    ]);

    // No composer.lock on disk — the opt-out short-circuits before touching it.
    expect((new CheckOctaneInstalledStep('testing'))())->toBe(StepResult::SKIPPED);
});

it('passes when laravel/octane is in the production packages', function (): void {
    writeComposerLock(['laravel/framework', 'laravel/octane']);

    expect((new CheckOctaneInstalledStep('testing'))())->toBe(StepResult::SUCCESS);
});

it('hard-fails when laravel/octane is absent', function (): void {
    writeComposerLock(['laravel/framework', 'laravel/sanctum']);

    expect(fn (): StepResult => (new CheckOctaneInstalledStep('testing'))())
        ->toThrow(RuntimeException::class, 'laravel/octane is not in composer.lock');
});

it('hard-fails when laravel/octane is only a dev dependency', function (): void {
    // The footgun: octane in require-dev passes a composer.json scan but is stripped
    // by the `--no-dev` production install, so the web container crash-loops.
    writeComposerLock(['laravel/framework'], devPackages: ['laravel/octane']);

    expect(fn (): StepResult => (new CheckOctaneInstalledStep('testing'))())
        ->toThrow(RuntimeException::class, 'laravel/octane is not in composer.lock');
});

it('hard-fails when composer.lock is missing', function (): void {
    // No lock written; can't verify the requirement, so fail closed.
    expect(fn (): StepResult => (new CheckOctaneInstalledStep('testing'))())
        ->toThrow(RuntimeException::class, 'composer.lock not found');
});
