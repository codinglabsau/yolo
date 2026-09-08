<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

use Closure;
use Throwable;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use Aws\CloudWatch\CloudWatchClient;
use Codinglabs\Yolo\YoloServiceProvider;
use Codinglabs\Yolo\Runtime\Contracts\Cpu;
use Illuminate\Contracts\Cache\Repository;
use Codinglabs\Yolo\Runtime\Contracts\Scraper;
use Codinglabs\Yolo\Runtime\Ssr\SaturationAwareSsrGateway;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\WebBurstPolicy;

/**
 * Publishes web-task saturation to CloudWatch for burst step-scaling: load on the tier
 * over its concurrency ceiling. On an Octane tier both terms come from the FrankenPHP
 * scrape on :2019 — `busy_workers` over `total_workers`. The busy gauge counts every
 * request dispatched to the worker script, those still waiting for a worker included,
 * so it carries the front queue and the time a busy worker spends outside Laravel's
 * handle span; the window's peak in-flight count inside the app ({@see InFlightRequests})
 * is kept only as a floor under it, so a scrape that lands on a momentary low can't
 * under-read a window the app itself saw busier. On a classic tier the ceiling is the
 * `max_threads` YOLO pinned (YOLO_BURST_THREADS — the scraped `total_threads` is only
 * the floor) and the numerator is `busy_threads + queue_depth`: a queued request is load
 * the ceiling hasn't absorbed. In both modes queueing pushes the value past 100 naturally
 * rather than tripping a floor, so one momentarily-queued request can't buy a task that
 * scale-in then holds for its window.
 *
 * Runs from `$app->terminating` ({@see YoloServiceProvider}) so the work rides a
 * request that already holds a CPU slice. The per-window cache claim is
 * load-bearing, not a nicety: under worker mode the request isn't finalised until
 * the terminate callback returns, so the scrape + put cost worker throughput —
 * only one request per window pays it, and only while hot. Cache keys are
 * task-scoped, so a shared Redis is correct: each task publishes its own
 * datapoint and the alarm takes Maximum across them. The constants are shared
 * with the alarm ({@see WebBurstPolicy}) — the contract between the two.
 *
 * Fallback breach: a scrape failure from a request that has CPU is evidence the
 * box is pinned, but only once primed by a prior success (so a boot race or
 * metrics misconfig stays silent), corroborated by a local cgroup CPU read
 * ({@see Cpu}) rather than retrying the starved endpoint. The asymmetry justifies
 * it: a false burst is additive and target-tracking scales it back in minutes; a
 * missed saturation is an outage.
 *
 * Diagnostics: the window's sample is also logged — the in-flight peak and raw
 * counter beside every FrankenPHP gauge the scrape carries ({@see Gauges::diagnostics()}),
 * and the CPU reading on the fallback path. The published value is one ratio; when
 * it disagrees with what the load balancer sees, the log is what shows which term
 * is wrong — a counter that under-reports, a pool with fewer ready workers than it
 * was sized for, or a queue the formula doesn't count. Hot windows (at or above the
 * emit floor) log at info so they're on by default; colder ones log at debug so the
 * ramp into a pin can be watched without paying for it at rest.
 */
class WorkerSaturationReporter
{
    private const float CPU_BREACH_THRESHOLD = 85.0;

    /**
     * Clear of both tiers' alarm lines with the strict `>` comparator. 100 would sit
     * exactly on the Octane line: this hook runs in `terminating`, after the response
     * has left, and FrankenPHP still counts the reporting worker as busy, so a
     * one-worker pool reads exactly 100 at idle — the value must be past the line,
     * not on it.
     */
    private const float BREACH_VALUE = 200.0;

    /** One success arms the fallback for the task's life. */
    private const int PRIMED_TTL = 86400;

    /** A stale baseline (no recent window) simply yields no delta. */
    private const int CPU_TTL = 30;

    /**
     * @param  Closure(): CloudWatchClient  $cloudwatch  Built only when a datapoint is
     *                                                   actually put: a classic tier
     *                                                   boots the framework per request,
     *                                                   so the reporter is constructed on
     *                                                   every request while the debounce
     *                                                   lets at most one per window publish.
     */
    public function __construct(
        private readonly Repository $cache,
        private readonly Closure $cloudwatch,
        private readonly Scraper $scraper,
        private readonly Cpu $cpu,
        private readonly InFlightRequests $inFlight,
        private readonly string $serviceName,
        private readonly string $taskId,
        // The classic tier's thread ceiling; null on an Octane tier, whose pool size
        // arrives with every scrape instead.
        private readonly ?int $threadCeiling = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function report(): void
    {
        // Whoever wins the window claim does the single scrape; everyone else returns.
        if (! $this->cache->add($this->key('window'), 1, WebBurstPolicy::POLL_INTERVAL)) {
            return;
        }

        $utilisation = $this->sampleCpu();
        $result = $this->scraper->scrape();

        // Always reset the peak, even on paths that don't use it, so the high-water
        // mark tracks this window rather than an inherited spike.
        $peak = $this->inFlight->flushPeak();

        match ($result->outcome) {
            ScrapeOutcome::Reading => $this->onReading($result, $peak),
            ScrapeOutcome::Failure => $this->onFailure($utilisation),
            // A 200 with no gauges is metrics-off — config, not load.
            ScrapeOutcome::Absent => null,
        };
    }

    private function onReading(ScrapeResult $result, int $peak): void
    {
        $this->cache->put($this->key('primed'), 1, self::PRIMED_TTL);

        $saturation = $result->totalWorkers !== null
            ? $this->workerSaturation($result->totalWorkers, $result->busyWorkers, $peak)
            : $this->threadSaturation($result);

        $this->logSample($saturation, $peak, $result->gauges);

        // Below the emit floor: near-zero cost at rest, nothing worth publishing.
        if ($saturation === null || $saturation < WebBurstPolicy::EMIT_FLOOR) {
            return;
        }

        $this->put($saturation);

        // No hold after a breach: the alarm needs consecutive breaching datapoints, and
        // the step policy's cooldown already stops a standing ALARM from piling on.
        if ($saturation > $this->alarmThreshold()) {
            $this->markSaturated();
        }
    }

    /**
     * The tier's line with the alarm's strict `>` comparator, so the SSR shed and the
     * alarm agree: an idle one-worker pool reads exactly 100 (see BREACH_VALUE) and
     * must shed nothing.
     */
    private function alarmThreshold(): int
    {
        return WebBurstPolicy::alarmThreshold(octane: $this->threadCeiling === null);
    }

    /**
     * Octane: busy workers as a percentage of the resident pool. Uncapped, since the
     * gauge counts queued requests too and the deeper reading earns the bigger step.
     * The in-flight peak floors it — a scrape samples one instant, and one that lands
     * on a momentary low can't under-read a window the app saw busier. That floor is
     * itself capped at the pool: the count can only exceed the pool through a leaked
     * request that never decremented (the safe upward bias), and an absurd datapoint
     * helps no one — the floor can at most read a full pool, never a queue.
     */
    private function workerSaturation(int $totalWorkers, int $busyWorkers, int $peak): float
    {
        return max($busyWorkers, min($peak, $totalWorkers)) / $totalWorkers * 100;
    }

    /**
     * Classic mode: busy plus queued requests as a percentage of the pinned ceiling.
     * Uncapped, since a queue is real demand past the ceiling and the deeper reading
     * earns the bigger step. Null without an injected ceiling — there is nothing
     * honest to divide by.
     */
    private function threadSaturation(ScrapeResult $result): ?float
    {
        if ($this->threadCeiling === null) {
            return null;
        }

        return ($result->busyThreads + $result->queueDepth) / $this->threadCeiling * 100;
    }

    private function onFailure(?float $utilisation): void
    {
        // Never primed → the endpoint has never answered here, so a failure is
        // config, not load.
        if ($this->cache->get($this->key('primed')) === null) {
            return;
        }

        $this->logger->warning('yolo-burst: metrics scrape failed on a primed task', [
            'task' => $this->taskId,
            'cpu' => $utilisation === null ? null : round($utilisation, 1),
            'breach' => $utilisation !== null && $utilisation >= self::CPU_BREACH_THRESHOLD,
        ]);

        if ($utilisation === null || $utilisation < self::CPU_BREACH_THRESHOLD) {
            return;
        }

        $this->put(self::BREACH_VALUE);
        $this->markSaturated();
    }

    /**
     * Logged before the emit floor, so the ramp is visible and not just the plateau.
     *
     * @param  array<string, int|null>  $gauges
     */
    private function logSample(?float $saturation, int $peak, array $gauges): void
    {
        $context = [
            'task' => $this->taskId,
            'saturation' => $saturation === null ? null : round($saturation, 1),
            'peak' => $peak,
            'current' => $this->inFlight->raw(),
            'ceiling' => $this->threadCeiling ?? $gauges['total_workers'] ?? null,
            ...$gauges,
        ];

        $saturation !== null && $saturation >= WebBurstPolicy::EMIT_FLOOR
            ? $this->logger->info('yolo-burst: window sample', $context)
            : $this->logger->debug('yolo-burst: window sample', $context);
    }

    /**
     * The same reading that trips burst scaling also sheds SSR to CSR
     * ({@see SaturationAwareSsrGateway}) — an instant local lever beside the slow
     * cloud one. Self-expires after the cooldown, so it fails open to SSR if the
     * reporter ever stops running.
     */
    private function markSaturated(): void
    {
        $this->cache->put(self::ssrBypassKey($this->taskId), 1, WebBurstPolicy::COOLDOWN);
    }

    /**
     * Defined once so the producer and the SSR gateway can never drift.
     */
    public static function ssrBypassKey(string $taskId): string
    {
        return "yolo-burst:{$taskId}:ssr-bypass";
    }

    /**
     * Null when there's no baseline yet or the cgroup can't be read; stores this
     * snapshot as the next baseline either way.
     */
    private function sampleCpu(): ?float
    {
        $snapshot = $this->cpu->snapshot();

        if (! $snapshot instanceof CpuSnapshot) {
            return null;
        }

        $previousUsage = $this->storedInt($this->key('cpu-usage'));
        $previousAt = $this->storedInt($this->key('cpu-at'));

        $this->cache->put($this->key('cpu-usage'), $snapshot->usageMicros, self::CPU_TTL);
        $this->cache->put($this->key('cpu-at'), $snapshot->atMicros, self::CPU_TTL);

        if ($previousUsage === null || $previousAt === null) {
            return null;
        }

        $wallMicros = $snapshot->atMicros - $previousAt;

        if ($wallMicros <= 0 || $snapshot->cores <= 0.0) {
            return null;
        }

        return ($snapshot->usageMicros - $previousUsage) / ($wallMicros * $snapshot->cores) * 100;
    }

    private function put(float $saturation): void
    {
        try {
            ($this->cloudwatch)()->putMetricData([
                'Namespace' => WebBurstPolicy::METRIC_NAMESPACE,
                'MetricData' => [[
                    'MetricName' => WebBurstPolicy::METRIC_NAME,
                    'Dimensions' => [['Name' => WebBurstPolicy::METRIC_DIMENSION, 'Value' => $this->serviceName]],
                    'Value' => round($saturation, 1),
                    'Unit' => 'Percent',
                    'StorageResolution' => 1,
                ]],
            ]);
        } catch (Throwable) {
            // a transient CloudWatch error must never bubble into the request
            // lifecycle — target-tracking still owns scaling
        }
    }

    private function storedInt(string $key): ?int
    {
        $value = $this->cache->get($key);

        return $value === null ? null : (int) $value;
    }

    private function key(string $suffix): string
    {
        return "yolo-burst:{$this->taskId}:{$suffix}";
    }
}
