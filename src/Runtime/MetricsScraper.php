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

        // Worker mode exposes both gauge families (the thread gauges underlie the
        // worker pool), so the worker gauges are checked first: only a body with no
        // usable worker total is read as threads. An Octane tier caught mid
        // worker-reload (a zero total) lands there too: the endpoint answered, so it
        // primes the reporter's fallback, then stays silent for lack of a thread
        // ceiling outside classic mode.
        $totalWorkers = Gauges::totalWorkers($body);

        if ($totalWorkers !== null) {
            return ScrapeResult::workers($totalWorkers);
        }

        return Gauges::hasThreads($body)
            ? ScrapeResult::threads(Gauges::busyThreads($body), Gauges::queueDepth($body))
            : ScrapeResult::absent();
    }
}
