<?php

declare(strict_types=1);

use Codinglabs\Yolo\Runtime\Gauges;

/** A worker-mode (Octane) scrape: the thread gauges underlie the worker pool, so both families appear. */
function workerModeMetrics(): string
{
    return "frankenphp_busy_threads 3\nfrankenphp_total_threads 4\nfrankenphp_busy_workers 3\nfrankenphp_total_workers 4\n";
}

/** A classic-mode scrape under load: 6 busy against a 4-thread floor, one request queued. */
function classicModeMetrics(): string
{
    return "frankenphp_busy_threads 6\nfrankenphp_total_threads 4\nfrankenphp_queue_depth 1\n";
}

it('parses the total worker count', function (): void {
    expect(Gauges::totalWorkers(workerModeMetrics()))->toBe(4);
});

it('reads a labelled worker gauge', function (): void {
    expect(Gauges::totalWorkers("frankenphp_total_workers{worker=\"/app\"} 4\n"))->toBe(4);
});

it('sums the total across every worker entry', function (): void {
    expect(Gauges::totalWorkers(
        "frankenphp_total_workers{worker=\"/a\"} 4\nfrankenphp_total_workers{worker=\"/b\"} 4\n"
    ))->toBe(8);
});

it('has no worker total in classic mode, where no worker script runs', function (): void {
    expect(Gauges::totalWorkers(classicModeMetrics()))->toBeNull();
});

it('has no worker total for a zero reading caught mid worker-reload', function (): void {
    expect(Gauges::totalWorkers("frankenphp_total_workers 0\n"))->toBeNull();
});

it('recognises the thread gauges in both modes', function (): void {
    expect(Gauges::hasThreads(classicModeMetrics()))->toBeTrue()
        ->and(Gauges::hasThreads(workerModeMetrics()))->toBeTrue()
        ->and(Gauges::hasThreads('nothing to see here'))->toBeFalse();
});

it('reads busy threads and queue depth from a classic-mode scrape', function (): void {
    expect(Gauges::busyThreads(classicModeMetrics()))->toBe(6)
        ->and(Gauges::queueDepth(classicModeMetrics()))->toBe(1);
});

it('reads an absent thread gauge as zero', function (): void {
    // An idle classic tier omits nothing, but a FrankenPHP without the queue gauge
    // must read as "no queue", never as a reading that can't be used.
    expect(Gauges::busyThreads("frankenphp_total_threads 4\n"))->toBe(0)
        ->and(Gauges::queueDepth("frankenphp_total_threads 4\n"))->toBe(0);
});
