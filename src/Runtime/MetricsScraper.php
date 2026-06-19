<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

use Illuminate\Support\Facades\Http;
use Codinglabs\Yolo\Runtime\Contracts\Scraper;
use Illuminate\Http\Client\ConnectionException;

/**
 * Scrapes FrankenPHP's Caddy admin metrics endpoint (localhost:2019/metrics) and
 * classifies the result. It runs inside a request's terminate phase — a context
 * that already holds a CPU slice — so a *failure* to get an answer (refused or
 * timed out) is evidence the box is too busy to respond, the burst panic signal.
 * The {@see WorkerSaturationReporter} only acts on a failure once primed by a prior
 * success, which is what keeps a boot race or metrics misconfig from false-firing.
 *
 * The endpoint binds container-loopback only, so this never leaves the task.
 */
class MetricsScraper implements Scraper
{
    public function __construct(
        private readonly string $url = 'http://localhost:2019/metrics',
        // Tight by design: this runs inline on the worker's terminate path, so a slow
        // scrape costs throughput. A miss falls through to the CPU fallback anyway.
        private readonly int $timeout = 1,
    ) {}

    public function scrape(): ScrapeResult
    {
        try {
            $body = Http::connectTimeout($this->timeout)
                ->timeout($this->timeout)
                ->get($this->url)
                ->body();
        } catch (ConnectionException) {
            // Connected-but-no-answer or refused — the endpoint couldn't respond, the
            // panic signal once primed. (A 4xx/5xx doesn't throw — it falls through to
            // parse, where no gauges reads as Absent: a misconfig, not load.)
            return ScrapeResult::failure();
        }

        $totalWorkers = WorkerPool::total($body);

        return $totalWorkers === null
            ? ScrapeResult::absent()
            : ScrapeResult::reading($totalWorkers);
    }
}
