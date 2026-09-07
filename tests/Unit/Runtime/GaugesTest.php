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

it('refuses a thread gauge missing beside total_threads rather than reading it as zero', function (): void {
    // FrankenPHP registers the three thread gauges together; one absent is a broken
    // scrape, and a silent zero would under-report saturation.
    expect(fn (): int => Gauges::busyThreads("frankenphp_total_threads 4\n"))
        ->toThrow(RuntimeException::class, 'frankenphp_busy_threads');
    expect(fn (): int => Gauges::queueDepth("frankenphp_total_threads 4\n"))
        ->toThrow(RuntimeException::class, 'frankenphp_queue_depth');
});

it('reads the diagnostic set by FrankenPHP name, null where a line is absent', function (): void {
    $metrics = "frankenphp_busy_threads 5\nfrankenphp_total_threads 9\nfrankenphp_queue_depth 0\n"
        . "frankenphp_total_workers{worker=\"/app/public/frankenphp-worker.php\"} 8\n"
        . "frankenphp_ready_workers{worker=\"/app/public/frankenphp-worker.php\"} 5\n"
        . "frankenphp_busy_workers{worker=\"/app/public/frankenphp-worker.php\"} 5\n"
        . "frankenphp_worker_crashes{worker=\"/app/public/frankenphp-worker.php\"} 3\n"
        . "frankenphp_worker_restarts{worker=\"/app/public/frankenphp-worker.php\"} 3\n";

    expect(Gauges::diagnostics($metrics))->toBe([
        'total_workers' => 8,
        'ready_workers' => 5,
        'busy_workers' => 5,
        'worker_queue_depth' => null,
        'worker_crashes' => 3,
        'worker_restarts' => 3,
        'total_threads' => 9,
        'busy_threads' => 5,
        'queue_depth' => 0,
    ]);
});

it('keeps the thread queue and the worker queue apart in the diagnostic set', function (): void {
    // `queue_depth` is the thread-level gauge; `worker_queue_depth` is per worker
    // script. A prefix match would fold one into the other.
    expect(Gauges::diagnostics("frankenphp_queue_depth 2\nfrankenphp_worker_queue_depth{worker=\"/a\"} 7\n"))
        ->toMatchArray(['queue_depth' => 2, 'worker_queue_depth' => 7]);
});
