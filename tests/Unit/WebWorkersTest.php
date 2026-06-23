<?php

declare(strict_types=1);

use Codinglabs\Yolo\WebWorkers;

function manifestWithWebTask(array $web): void
{
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [...$web, 'autoscaling' => ['min' => 1, 'max' => 6]]],
    ]);
}

it('pins the pool to 16 workers per real vCPU from the default 0.5 vCPU task', function (): void {
    // No cpu/memory set → web defaults (512 units = 0.5 vCPU, 1024 MB).
    // 16 × 0.5 = 8 workers; memory cap 1024/64 = 16 doesn't bind.
    manifestWithWebTask([]);

    expect(WebWorkers::count())->toBe(8);
});

it('scales the pool with the task vCPU allocation', function (int $cpu, int $memory, int $expected): void {
    manifestWithWebTask(['cpu' => $cpu, 'memory' => $memory]);

    expect(WebWorkers::count())->toBe($expected);
})->with([
    '0.25 vCPU' => [256, 512, 4],
    '0.5 vCPU' => [512, 1024, 8],
    '1 vCPU' => [1024, 2048, 16],
    '2 vCPU' => [2048, 4096, 32],
]);

it('caps the pool at what memory can hold (a resident worker is ~64 MB)', function (): void {
    // 1 vCPU would give 16 by CPU, but 512 MB only holds 512/64 = 8.
    manifestWithWebTask(['cpu' => 1024, 'memory' => 512]);

    expect(WebWorkers::count())->toBe(8);
});

it('never drops below one worker on a deliberately tiny task', function (): void {
    // 32 MB holds zero whole 64 MB workers → clamped up to 1.
    manifestWithWebTask(['cpu' => 256, 'memory' => 32]);

    expect(WebWorkers::count())->toBe(1);
});
