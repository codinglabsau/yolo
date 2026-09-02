<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

use Closure;
use Throwable;
use Aws\CloudWatch\CloudWatchClient;
use Codinglabs\Yolo\YoloServiceProvider;
use Codinglabs\Yolo\Runtime\Contracts\Cpu;
use Illuminate\Contracts\Cache\Repository;
use Codinglabs\Yolo\Runtime\Contracts\Scraper;
use Codinglabs\Yolo\Runtime\Ssr\SaturationAwareSsrGateway;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\WebBurstPolicy;

/**
 * Publishes web-task saturation to CloudWatch for burst step-scaling: the window's peak
 * in-flight requests ({@see InFlightRequests}) over the tier's concurrency ceiling. On an
 * Octane tier the ceiling is the FrankenPHP worker-pool size scraped from :2019; the
 * numerator is counted directly because the `busy_workers` gauge, sampled from this
 * after-response hook, under-reports the very pin burst exists to catch. On a classic tier
 * the ceiling is the `max_threads` YOLO pinned (YOLO_BURST_THREADS — the scraped
 * `total_threads` is only the floor) and the numerator is `busy_threads + queue_depth`:
 * that gauge is sampled while the sampling thread is still busy, and a queued request is
 * load the ceiling hasn't absorbed. Queueing pushes the value past 100 naturally rather
 * than tripping a floor, so one momentarily-queued request can't buy a task that scale-in
 * then holds for its window.
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
 */
class WorkerSaturationReporter
{
    private const float CPU_BREACH_THRESHOLD = 85.0;

    /** Above the alarm threshold, so a fallback breach trips. */
    private const float BREACH_VALUE = 100.0;

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
            ? $this->workerSaturation($result->totalWorkers, $peak)
            : $this->threadSaturation($result);

        // Below the emit floor: near-zero cost at rest, nothing worth publishing.
        if ($saturation === null || $saturation < WebBurstPolicy::EMIT_FLOOR) {
            return;
        }

        $this->put($saturation);

        // Hold the window at the cooldown so we don't pile on while the new task boots.
        if ($saturation >= WebBurstPolicy::ALARM_THRESHOLD) {
            $this->markSaturated();
            $this->cache->put($this->key('window'), 1, WebBurstPolicy::COOLDOWN);
        }
    }

    /**
     * Octane: saturation as a percentage of the resident pool. Capped at 100: the
     * in-flight count can only exceed the pool size if a leaked request never
     * decremented (the safe upward bias), and an absurd datapoint helps no one — 100
     * already trips the +2 step.
     */
    private function workerSaturation(int $totalWorkers, int $peak): float
    {
        return min(100.0, $peak / $totalWorkers * 100);
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

        if ($utilisation === null || $utilisation < self::CPU_BREACH_THRESHOLD) {
            return;
        }

        $this->put(self::BREACH_VALUE);
        $this->markSaturated();
        $this->cache->put($this->key('window'), 1, WebBurstPolicy::COOLDOWN);
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
