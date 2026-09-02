<?php

declare(strict_types=1);

namespace Codinglabs\Yolo\Runtime;

use Closure;
use Throwable;
use Codinglabs\Yolo\WebThreads;
use Aws\CloudWatch\CloudWatchClient;
use Codinglabs\Yolo\YoloServiceProvider;
use Codinglabs\Yolo\Runtime\Contracts\Cpu;
use Illuminate\Contracts\Cache\Repository;
use Codinglabs\Yolo\Runtime\Contracts\Scraper;
use Codinglabs\Yolo\Runtime\Ssr\SaturationAwareSsrGateway;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\WebBurstPolicy;

/**
 * Publishes web-task saturation to CloudWatch for burst step-scaling. Saturation is the
 * window's **peak in-flight request count** ({@see InFlightRequests}) over the tier's
 * concurrency ceiling. On an Octane tier that ceiling is the FrankenPHP **worker-pool
 * size** (scraped from :2019 — the one number the runtime can't otherwise know); the
 * numerator is counted directly rather than read from FrankenPHP's `busy_workers` gauge
 * because that gauge, sampled from this after-response hook, under-reports the very pin
 * burst exists to catch — high when idle, low when pinned. On a classic tier the ceiling
 * is the `max_threads` YOLO pinned ({@see WebThreads}, injected as YOLO_BURST_THREADS —
 * the scraped `total_threads` is only the floor) and the numerator is
 * `busy_threads + queue_depth`: the thread gauge is sampled while the sampling
 * request's own thread is still busy, so it doesn't self-undercount, and a queued
 * request is load the ceiling hasn't absorbed. Queueing pushes the value past 100
 * naturally rather than tripping a floor: a single momentarily-queued request must not
 * buy a task that scale-in then holds for its fifteen-minute window.
 *
 * It's invoked from an after-response hook the {@see YoloServiceProvider} registers
 * ($app->terminating), so the work rides on a request that already holds a CPU slice
 * instead of a separate loop fighting for one on a pinned box.
 *
 * report() runs on every request but is debounced to real work at most once per
 * window via an atomic per-task claim in the app's cache (Redis on a YOLO app), whose
 * TTL encodes the poll interval, stretched to the cooldown after a tripping datapoint —
 * so CloudWatch is touched only while hot and only as often as a scale can act. This
 * throttle is load-bearing, not a nicety: under FrankenPHP worker mode the request
 * isn't finalised until the terminate callback returns, so the scrape + put cost the
 * worker throughput — which is exactly why only one request per window pays it, and
 * only while hot. The cache keys are task-scoped, so a shared Redis is correct: each
 * task still publishes its own datapoint and the alarm takes Maximum across them.
 *
 * The metric, namespace, dimension, floor, threshold and cooldown are the same
 * constants the alarm reads ({@see WebBurstPolicy}) — the contract between the two.
 *
 * Fallback breach: a scrape *failure* from a request that has CPU is evidence the box
 * is pinned, but only once "primed" by a prior success (proof the endpoint is
 * configured and was reachable) — so a boot race or metrics misconfig stays silent.
 * Rather than retry the possibly-starved endpoint, it corroborates with a cheap local
 * cgroup CPU read ({@see Cpu}) — a file the worker can always read; high CPU publishes
 * a tripping value. The asymmetry justifies it: a false burst is additive and target-
 * tracking scales it back in minutes; a missed saturation is an outage.
 */
class WorkerSaturationReporter
{
    /** Container CPU % (of allocation) over the last window at which a failed scrape is treated as a breach. */
    private const float CPU_BREACH_THRESHOLD = 85.0;

    /** The saturation value a fallback breach publishes — above the alarm threshold, so it trips. */
    private const float BREACH_VALUE = 100.0;

    /** Primed flag TTL — long-lived; one success arms the fallback for the task's life. */
    private const int PRIMED_TTL = 86400;

    /** CPU-baseline TTL — a stale baseline (no recent window) simply yields no delta. */
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
        // Claim this window. Whoever wins does the single scrape; everyone else is
        // "still sleeping / just evaluated" and returns — so no work is wasted on
        // internal requests, and CloudWatch is never touched out of cadence. The
        // debounce keeps this off the hot path: at most one request per window pays
        // for the scrape + put, and only while the service is actually hot.
        if (! $this->cache->add($this->key('window'), 1, WebBurstPolicy::POLL_INTERVAL)) {
            return;
        }

        $utilisation = $this->sampleCpu();
        $result = $this->scraper->scrape();

        // Always read-and-reset the window's peak so the high-water mark tracks this
        // window, not an inherited spike — even on the paths that don't use it.
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
        // A clean reading primes the reporter — proof the endpoint is reachable, so a
        // later failure can be trusted enough to corroborate against CPU.
        $this->cache->put($this->key('primed'), 1, self::PRIMED_TTL);

        $saturation = $result->totalWorkers !== null
            ? $this->workerSaturation($result->totalWorkers, $peak)
            : $this->threadSaturation($result);

        // Below the emit floor: near-zero cost at rest, nothing worth publishing.
        if ($saturation === null || $saturation < WebBurstPolicy::EMIT_FLOOR) {
            return;
        }

        $this->put($saturation);

        // A tripping datapoint already steps the desired count out; hold the window at
        // the cooldown so we don't pile on while the new task boots.
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
        // Never primed → the endpoint has never answered here (boot race or metrics
        // misconfig), so a failure is config, not load. Stay silent.
        if ($this->cache->get($this->key('primed')) === null) {
            return;
        }

        // The scrape couldn't get an answer from a request that holds a CPU slice.
        // Corroborate with the local CPU read rather than retrying the starved
        // endpoint: high CPU ⇒ the box is pinned ⇒ breach.
        if ($utilisation === null || $utilisation < self::CPU_BREACH_THRESHOLD) {
            return;
        }

        $this->put(self::BREACH_VALUE);
        $this->markSaturated();
        $this->cache->put($this->key('window'), 1, WebBurstPolicy::COOLDOWN);
    }

    /**
     * Flag this task as saturated so the SSR gateway sheds rendering to CSR (see
     * {@see SaturationAwareSsrGateway}). The same
     * worker-saturation reading that trips burst step-scaling also turns SSR off —
     * one signal, an instant local lever (shed) alongside the slow cloud one (scale).
     * The flag self-expires after the cooldown, so it clears once the box stops
     * tripping and fails open to SSR if the reporter ever stops running.
     */
    private function markSaturated(): void
    {
        $this->cache->put(self::ssrBypassKey($this->taskId), 1, WebBurstPolicy::COOLDOWN);
    }

    /**
     * The per-task cache key the reporter sets while saturated and the SSR gateway
     * reads. Defined once here so the producer and consumer can never drift.
     */
    public static function ssrBypassKey(string $taskId): string
    {
        return "yolo-burst:{$taskId}:ssr-bypass";
    }

    /**
     * CPU utilisation as a percentage of the task's allocation since the previous
     * window, or null when there's no baseline yet or the cgroup can't be read. Stores
     * this snapshot as the next baseline either way.
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
            // Fail safe: a transient CloudWatch error must never bubble into the
            // request lifecycle — target-tracking still owns scaling.
        }
    }

    /** A previously-stored integer value, or null when absent — the cache returns mixed. */
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
