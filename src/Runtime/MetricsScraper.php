<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

use Illuminate\Support\Facades\Http;
use Codinglabs\Yolo\Runtime\Contracts\Scraper;
use Illuminate\Http\Client\ConnectionException;

/**
 * Runs inside a request's terminate phase — a context that already holds a CPU
 * slice — so a failure to get an answer is evidence the box is too busy to
 * respond: the burst panic signal ({@see WorkerSaturationReporter} acts on it
 * only once primed). The endpoint binds container-loopback only.
 */
class MetricsScraper implements Scraper
{
    public function __construct(
        private readonly string $url = 'http://localhost:2019/metrics',
        // tight by design: a slow scrape on the terminate path costs throughput,
        // and a miss falls through to the CPU fallback anyway
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
            // A 4xx/5xx doesn't throw — it falls through to parse, where no gauges
            // reads as Absent: a misconfig, not load.
            return ScrapeResult::failure();
        }

        $totalWorkers = WorkerPool::total($body);

        return $totalWorkers === null
            ? ScrapeResult::absent()
            : ScrapeResult::reading($totalWorkers);
    }
}
