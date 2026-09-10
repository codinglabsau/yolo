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

it('pins the pool to 8 workers per real vCPU from the default 0.5 vCPU task', function (): void {
    // No cpu/memory set → web defaults (512 units = 0.5 vCPU, 1024 MB).
    // 8 × 0.5 = 4 workers; memory cap 1024/64 = 16 doesn't bind.
    manifestWithWebTask([]);

    expect(WebWorkers::count())->toBe(4);
});

it('scales the pool with the task vCPU allocation', function (int $cpu, int $memory, int $expected): void {
    manifestWithWebTask(['cpu' => $cpu, 'memory' => $memory]);

    expect(WebWorkers::count())->toBe($expected);
})->with([
    '0.25 vCPU' => [256, 512, 2],
    '0.5 vCPU' => [512, 1024, 4],
    '1 vCPU' => [1024, 2048, 8],
    '2 vCPU' => [2048, 4096, 16],
]);

it('caps the pool at what memory can hold (a resident worker is ~64 MB)', function (): void {
    // 2 vCPU would give 16 by CPU, but 512 MB only holds 512/64 = 8.
    manifestWithWebTask(['cpu' => 2048, 'memory' => 512]);

    expect(WebWorkers::count())->toBe(8);
});

it('never drops below one worker on a deliberately tiny task', function (): void {
    // 32 MB holds zero whole 64 MB workers → clamped up to 1.
    manifestWithWebTask(['cpu' => 256, 'memory' => 32]);

    expect(WebWorkers::count())->toBe(1);
});

it('takes an explicit tasks.web.concurrency verbatim — absolute, not per vCPU', function (): void {
    // 1 vCPU would derive 8; a CPU-bound app pins the pool to what its cores can clear.
    manifestWithWebTask(['cpu' => 1024, 'memory' => 2048, 'concurrency' => 4]);

    expect(WebWorkers::count())->toBe(4);
});

it('lets an explicit concurrency exceed what the formula would derive, memory bound included', function (): void {
    // 0.25 vCPU / 512 MB derives 2 (memory would hold 8); the operator has sized it.
    manifestWithWebTask(['cpu' => 256, 'memory' => 512, 'concurrency' => 12]);

    expect(WebWorkers::count())->toBe(12);
});

it('falls back to the formula when the key is absent', function (): void {
    manifestWithWebTask(['cpu' => 1024, 'memory' => 2048]);

    expect(WebWorkers::count())->toBe(8);
});
