<?php

declare(strict_types=1);

use Codinglabs\Yolo\WebThreads;

function manifestWithClassicWebTask(array $web = []): void
{
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [...$web, 'octane' => false]],
    ]);
}

it('pins both bounds off the default 0.5 vCPU task', function (): void {
    // No cpu/memory set → web defaults (512 units = 0.5 vCPU, 1024 MB).
    // Floor 8 × 0.5 = 4, ceiling 16 × 0.5 = 8; memory holds 1024/48 = 21, so
    // neither is memory-bound.
    manifestWithClassicWebTask();

    expect(WebThreads::minimum())->toBe(4)
        ->and(WebThreads::maximum())->toBe(8);
});

it('scales both bounds with the task vCPU allocation', function (int $cpu, int $memory, int $floor, int $ceiling): void {
    manifestWithClassicWebTask(['cpu' => $cpu, 'memory' => $memory]);

    expect(WebThreads::minimum())->toBe($floor)
        ->and(WebThreads::maximum())->toBe($ceiling);
})->with([
    '0.25 vCPU' => [256, 512, 2, 4],
    '0.5 vCPU' => [512, 1024, 4, 8],
    '1 vCPU' => [1024, 2048, 8, 16],
    '2 vCPU' => [2048, 4096, 16, 32],
]);

it('holds the ceiling at twice the floor so a burst is absorbed but not unbounded', function (): void {
    manifestWithClassicWebTask(['cpu' => 1024, 'memory' => 2048]);

    expect(WebThreads::maximum())->toBe(WebThreads::minimum() * 2);
});

it('caps both bounds at what memory can hold', function (): void {
    // 2 vCPU would give 16/32 by CPU, but 512 MB only budgets 512/48 = 10 busy threads.
    manifestWithClassicWebTask(['cpu' => 2048, 'memory' => 512]);

    expect(WebThreads::minimum())->toBe(10)
        ->and(WebThreads::maximum())->toBe(10);
});

it('collapses to a fixed pool rather than an inverted range when memory binds hardest', function (): void {
    manifestWithClassicWebTask(['cpu' => 2048, 'memory' => 512]);

    expect(WebThreads::maximum())->toBeGreaterThanOrEqual(WebThreads::minimum());
});

it('never drops below one thread on a deliberately tiny task', function (): void {
    // 32 MB budgets zero whole 48 MB threads → both clamped up to 1.
    manifestWithClassicWebTask(['cpu' => 256, 'memory' => 32]);

    expect(WebThreads::minimum())->toBe(1)
        ->and(WebThreads::maximum())->toBe(1);
});

it('fixes the pool at an explicit tasks.web.concurrency — the ceiling is the ceiling, no burst headroom', function (): void {
    // 1 vCPU would derive 8/16. The key is for a CPU-bound app, where a thread past what
    // the cores can clear is contention, not capacity, so an influx queues at the task
    // rather than growing the pool.
    manifestWithClassicWebTask(['cpu' => 1024, 'memory' => 2048, 'concurrency' => 4]);

    expect(WebThreads::minimum())->toBe(4)
        ->and(WebThreads::maximum())->toBe(4);
});

it('does not memory-cap an explicit concurrency — the operator has sized it', function (): void {
    // 512 MB would budget 10 by the formula.
    manifestWithClassicWebTask(['cpu' => 1024, 'memory' => 512, 'concurrency' => 12]);

    expect(WebThreads::minimum())->toBe(12)
        ->and(WebThreads::maximum())->toBe(12);
});

it('falls back to the formula when the key is absent', function (): void {
    manifestWithClassicWebTask(['cpu' => 1024, 'memory' => 2048]);

    expect(WebThreads::minimum())->toBe(8)
        ->and(WebThreads::maximum())->toBe(16);
});
