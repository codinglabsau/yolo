<?php

declare(strict_types=1);

use Codinglabs\Yolo\WebThreads;
use Codinglabs\Yolo\WebWorkers;
use Codinglabs\Yolo\WebConcurrency;

function manifestWithWebMode(bool $octane, array $web = []): void
{
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [...$web, 'octane' => $octane]],
    ]);
}

it('is the resident worker pool on an Octane tier', function (): void {
    manifestWithWebMode(octane: true);

    expect(WebConcurrency::ceiling())->toBe(WebWorkers::count())
        ->and(WebConcurrency::ceiling())->toBe(8);
});

it('is the thread ceiling, not the floor, on a classic-mode tier', function (): void {
    manifestWithWebMode(octane: false);

    expect(WebConcurrency::ceiling())->toBe(WebThreads::maximum())
        ->and(WebConcurrency::ceiling())->toBe(16)
        ->and(WebConcurrency::ceiling())->not->toBe(WebThreads::minimum());
});

it('defaults to the Octane pool when the mode is not declared', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => []],
    ]);

    expect(WebConcurrency::ceiling())->toBe(WebWorkers::count());
});
