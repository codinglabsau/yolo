<?php

declare(strict_types=1);

use Codinglabs\Yolo\Runtime\WorkerPool;

it('parses the total worker count', function (): void {
    expect(WorkerPool::total("frankenphp_busy_workers 3\nfrankenphp_total_workers 4\n"))->toBe(4);
});

it('reads a labelled gauge', function (): void {
    expect(WorkerPool::total("frankenphp_total_workers{worker=\"/app\"} 4\n"))->toBe(4);
});

it('sums the total across every worker entry', function (): void {
    expect(WorkerPool::total(
        "frankenphp_total_workers{worker=\"/a\"} 4\nfrankenphp_total_workers{worker=\"/b\"} 4\n"
    ))->toBe(8);
});

it('is null when the total gauge is absent (metrics off / classic mode)', function (): void {
    expect(WorkerPool::total("frankenphp_busy_workers 1\n"))->toBeNull();
});

it('is null for a zero-total reading caught mid worker-reload', function (): void {
    expect(WorkerPool::total("frankenphp_total_workers 0\n"))->toBeNull();
});
