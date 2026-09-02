<?php

declare(strict_types=1);

use Tests\TestbenchCase;
use Illuminate\Support\Facades\Http;
use Codinglabs\Yolo\Runtime\ScrapeOutcome;
use Codinglabs\Yolo\Runtime\MetricsScraper;
use Illuminate\Http\Client\ConnectionException;

uses(TestbenchCase::class);

it('classifies a worker-gauge payload as a reading carrying the worker-pool size', function (): void {
    // Worker mode exposes both gauge families; the worker pool is the one that counts.
    Http::fake(['*' => Http::response(
        "frankenphp_busy_threads 3\nfrankenphp_total_threads 4\nfrankenphp_busy_workers 3\nfrankenphp_total_workers 4\n"
    )]);

    $result = (new MetricsScraper())->scrape();

    expect($result->outcome)->toBe(ScrapeOutcome::Reading)
        ->and($result->totalWorkers)->toBe(4);
});

it('classifies a thread-gauge payload as a classic-mode reading carrying busy threads and queue depth', function (): void {
    // Classic mode under load: 6 busy against a 4-thread floor, one request queued.
    Http::fake(['*' => Http::response(
        "frankenphp_busy_threads 6\nfrankenphp_total_threads 4\nfrankenphp_queue_depth 1\n"
    )]);

    $result = (new MetricsScraper())->scrape();

    expect($result->outcome)->toBe(ScrapeOutcome::Reading)
        ->and($result->totalWorkers)->toBeNull()
        ->and($result->busyThreads)->toBe(6)
        ->and($result->queueDepth)->toBe(1);
});

it('classifies a gaugeless 200 as absent (metrics off)', function (): void {
    Http::fake(['*' => Http::response('nothing to see here')]);

    expect((new MetricsScraper())->scrape()->outcome)->toBe(ScrapeOutcome::Absent);
});

it('classifies a connection failure as a failure', function (): void {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect((new MetricsScraper())->scrape()->outcome)->toBe(ScrapeOutcome::Failure);
});
