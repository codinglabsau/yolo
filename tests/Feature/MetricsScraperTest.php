<?php

declare(strict_types=1);

use Tests\TestbenchCase;
use Illuminate\Support\Facades\Http;
use Codinglabs\Yolo\Runtime\ScrapeOutcome;
use Codinglabs\Yolo\Runtime\MetricsScraper;
use Illuminate\Http\Client\ConnectionException;

uses(TestbenchCase::class);

it('classifies a gauge payload as a reading carrying the worker-pool size', function (): void {
    Http::fake(['*' => Http::response("frankenphp_busy_workers 3\nfrankenphp_total_workers 4\n")]);

    $result = (new MetricsScraper())->scrape();

    expect($result->outcome)->toBe(ScrapeOutcome::Reading)
        ->and($result->totalWorkers)->toBe(4);
});

it('classifies a gaugeless 200 as absent (metrics off / classic mode)', function (): void {
    Http::fake(['*' => Http::response('nothing to see here')]);

    expect((new MetricsScraper())->scrape()->outcome)->toBe(ScrapeOutcome::Absent);
});

it('classifies a connection failure as a failure', function (): void {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect((new MetricsScraper())->scrape()->outcome)->toBe(ScrapeOutcome::Failure);
});
