<?php

declare(strict_types=1);

use Aws\Result;
use Aws\MockHandler;
use Aws\CommandInterface;
use GuzzleHttp\Promise\Create;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Aws\CloudWatch\CloudWatchClient;
use Codinglabs\Yolo\Runtime\CpuSnapshot;
use Codinglabs\Yolo\Runtime\ScrapeResult;
use Codinglabs\Yolo\Runtime\Contracts\Cpu;
use Codinglabs\Yolo\Runtime\InFlightRequests;
use Codinglabs\Yolo\Runtime\Contracts\Scraper;
use Codinglabs\Yolo\Runtime\WorkerSaturationReporter;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\WebBurstPolicy;

/** The window key the reporter claims for task 'task-1' — forget it to simulate the next window. */
const WINDOW_KEY = 'yolo-burst:task-1:window';

function arrayCache(): Repository
{
    return new Repository(new ArrayStore());
}

/** A scraper that returns a queued sequence of results, then Absent forever. */
function queuedScraper(array $results): Scraper
{
    return new class($results) implements Scraper
    {
        public function __construct(public array $results) {}

        public function scrape(): ScrapeResult
        {
            return array_shift($this->results) ?? ScrapeResult::absent();
        }
    };
}

/** A CPU reader whose snapshot is null — CPU is irrelevant to the test. */
function nullCpu(): Cpu
{
    return new class() implements Cpu
    {
        public function snapshot(): ?CpuSnapshot
        {
            return null;
        }
    };
}

/** A CPU reader returning queued snapshots in order, then null. */
function queuedCpu(array $snapshots): Cpu
{
    return new class($snapshots) implements Cpu
    {
        public function __construct(public array $snapshots) {}

        public function snapshot(): ?CpuSnapshot
        {
            return array_shift($this->snapshots);
        }
    };
}

/** An in-flight gauge seeded so the window peaks at the given concurrency. */
function inFlightPeaking(Repository $cache, int $peak): InFlightRequests
{
    $gauge = new InFlightRequests($cache, 'task-1');

    foreach (range(1, max(0, $peak)) as $ignored) {
        $gauge->enter();
    }

    return $gauge;
}

function recordingCloudWatch(array &$captured): CloudWatchClient
{
    $mock = new class($captured) extends MockHandler
    {
        public function __construct(protected array &$captured) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            $this->captured[] = $cmd->toArray()['MetricData'][0]['Value'];

            return Create::promiseFor(new Result());
        }
    };

    return new CloudWatchClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]);
}

function burstReporter(Repository $cache, Scraper $scraper, Cpu $cpu, InFlightRequests $inFlight, array &$published, ?int $threadCeiling = null): WorkerSaturationReporter
{
    $cloudwatch = function () use (&$published): CloudWatchClient {
        return recordingCloudWatch($published);
    };

    return new WorkerSaturationReporter($cache, $cloudwatch, $scraper, $cpu, $inFlight, 'svc', 'task-1', $threadCeiling);
}

/** A cache that records every put's TTL, so a window hold can be asserted. */
function ttlRecordingCache(array &$puts): Repository
{
    return new class(new ArrayStore(), $puts) extends Repository
    {
        private array $puts;

        public function __construct(ArrayStore $store, array &$puts)
        {
            parent::__construct($store);
            $this->puts = &$puts;
        }

        public function put($key, $value, $ttl = null)
        {
            $this->puts[] = [$key, $ttl];

            return parent::put($key, $value, $ttl);
        }
    };
}

/** Two snapshots a window apart whose delta is the given CPU % of a 0.5-core task. */
function cpuRamp(float $percent): array
{
    $wallMicros = 1_000_000;
    $cores = 0.5;
    $usedMicros = (int) ($percent / 100 * $wallMicros * $cores);

    return [new CpuSnapshot(0, 0, $cores), new CpuSnapshot($usedMicros, $wallMicros, $cores)];
}

it('publishes saturation (peak in-flight ÷ pool) at or above the emit floor', function (): void {
    $published = [];
    $cache = arrayCache();
    // 3 in flight of a 4-worker pool → 75%.
    $reporter = burstReporter($cache, queuedScraper([ScrapeResult::workers(4)]), nullCpu(), inFlightPeaking($cache, 3), $published);

    $reporter->report();

    expect($published)->toBe([75.0]);
});

it('stays silent for saturation below the emit floor', function (): void {
    $published = [];
    $cache = arrayCache();
    // 1 of 4 → 25%, below the 50% floor.
    $reporter = burstReporter($cache, queuedScraper([ScrapeResult::workers(4)]), nullCpu(), inFlightPeaking($cache, 1), $published);

    $reporter->report();

    expect($published)->toBe([]);
});

it('caps a leaked over-pool count at 100 rather than publishing an absurd value', function (): void {
    $published = [];
    $cache = arrayCache();
    // 6 in flight on a 4-worker pool (a leaked, never-decremented request) → capped to 100.
    $reporter = burstReporter($cache, queuedScraper([ScrapeResult::workers(4)]), nullCpu(), inFlightPeaking($cache, 6), $published);

    $reporter->report();

    expect($published)->toBe([100.0]);
});

it('does real work at most once per window no matter the request rate', function (): void {
    $published = [];
    $cache = arrayCache();
    $reporter = burstReporter($cache, queuedScraper([ScrapeResult::workers(4), ScrapeResult::workers(4)]), nullCpu(), inFlightPeaking($cache, 3), $published);

    $reporter->report();
    $reporter->report(); // window still claimed → no scrape, no publish

    expect($published)->toBe([75.0]);
});

it('stays silent when metrics are absent (off)', function (): void {
    $published = [];
    $cache = arrayCache();
    $reporter = burstReporter($cache, queuedScraper([ScrapeResult::absent()]), nullCpu(), inFlightPeaking($cache, 4), $published);

    $reporter->report();

    expect($published)->toBe([]);
});

it('never breaches on a failure before it has been primed by a success — even at high CPU', function (): void {
    $published = [];
    $cache = arrayCache();
    $reporter = burstReporter($cache, queuedScraper([ScrapeResult::failure(), ScrapeResult::failure()]), queuedCpu(cpuRamp(100.0)), inFlightPeaking($cache, 0), $published);

    foreach (range(1, 2) as $ignored) {
        $cache->forget(WINDOW_KEY);
        $reporter->report();
    }

    expect($published)->toBe([]);
});

it('breaches with a tripping value when a primed scrape fails and CPU is high', function (): void {
    $published = [];
    $cache = arrayCache();
    $reporter = burstReporter($cache, queuedScraper([
        ScrapeResult::workers(4), // primes (1 of 4 = 25%, below floor → no publish) + seeds the CPU baseline
        ScrapeResult::failure(),  // scrape fails; CPU corroborates
    ]), queuedCpu(cpuRamp(100.0)), inFlightPeaking($cache, 1), $published);

    foreach (range(1, 2) as $ignored) {
        $cache->forget(WINDOW_KEY);
        $reporter->report();
    }

    expect($published)->toBe([100.0]);
    expect((float) WebBurstPolicy::ALARM_THRESHOLD)->toBeLessThan(100.0);
});

it('stays silent when a primed scrape fails but CPU is low (a transient, not a pin)', function (): void {
    $published = [];
    $cache = arrayCache();
    $reporter = burstReporter($cache, queuedScraper([
        ScrapeResult::workers(4),
        ScrapeResult::failure(),
    ]), queuedCpu(cpuRamp(20.0)), inFlightPeaking($cache, 1), $published);

    foreach (range(1, 2) as $ignored) {
        $cache->forget(WINDOW_KEY);
        $reporter->report();
    }

    expect($published)->toBe([]);
});

it('stays silent when a primed scrape fails and CPU cannot be read', function (): void {
    $published = [];
    $cache = arrayCache();
    $reporter = burstReporter($cache, queuedScraper([
        ScrapeResult::workers(4),
        ScrapeResult::failure(),
    ]), queuedCpu([new CpuSnapshot(0, 0, 0.5)]), inFlightPeaking($cache, 1), $published); // no second snapshot → null on the failure window

    foreach (range(1, 2) as $ignored) {
        $cache->forget(WINDOW_KEY);
        $reporter->report();
    }

    expect($published)->toBe([]);
});

it('divides classic-mode saturation by the pinned thread ceiling, never the scraped floor', function (): void {
    $published = [];
    $cache = arrayCache();
    // 6 busy threads on a tier pinned at num_threads 4 / max_threads 8: total_threads
    // reports the 4-thread floor, so busy exceeds it. Against the ceiling that's 75%.
    // The in-flight peak is seeded low so the worker arithmetic would read 12.5.
    $reporter = burstReporter($cache, queuedScraper([ScrapeResult::threads(6, 0)]), nullCpu(), inFlightPeaking($cache, 1), $published, threadCeiling: 8);

    $reporter->report();

    expect($published)->toBe([75.0]);
});

it('counts a queued request as load on top of the busy threads', function (): void {
    $published = [];
    $cache = arrayCache();
    // 6 busy, 1 queued, ceiling 8 → 7/8 = 87.5%. One queued request moves the
    // reading by a thread's worth, not to a trip.
    $reporter = burstReporter($cache, queuedScraper([ScrapeResult::threads(6, 1)]), nullCpu(), inFlightPeaking($cache, 6), $published, threadCeiling: 8);

    $reporter->report();

    expect($published)->toBe([87.5]);
});

it('lets a queue push classic saturation past 100', function (): void {
    $published = [];
    $cache = arrayCache();
    // A full ceiling with two waiting: 10/8 = 125%. Uncapped, so the deeper overshoot
    // keeps landing the bigger step.
    $reporter = burstReporter($cache, queuedScraper([ScrapeResult::threads(8, 2)]), nullCpu(), inFlightPeaking($cache, 8), $published, threadCeiling: 8);

    $reporter->report();

    expect($published)->toBe([125.0]);
    expect($cache->get('yolo-burst:task-1:ssr-bypass'))->not->toBeNull();
});

it('holds the window at the cooldown after a tripping datapoint', function (): void {
    $published = [];
    $puts = [];
    $cache = ttlRecordingCache($puts);
    $reporter = burstReporter($cache, queuedScraper([ScrapeResult::threads(8, 2)]), nullCpu(), inFlightPeaking($cache, 8), $published, threadCeiling: 8);

    $reporter->report();

    // One breach already steps the desired count out; the next scrape waits for it.
    expect($puts)->toContain([WINDOW_KEY, WebBurstPolicy::COOLDOWN]);
});

it('does not trip on a queued request while the ceiling has room', function (): void {
    $published = [];
    $cache = arrayCache();
    // 2 busy + 1 queued of 8 = 37.5%: below the emit floor, so nothing is published
    // and no task is bought for a momentary queue. The peak is seeded high to prove
    // the classic branch ignores it.
    $reporter = burstReporter($cache, queuedScraper([ScrapeResult::threads(2, 1)]), nullCpu(), inFlightPeaking($cache, 8), $published, threadCeiling: 8);

    $reporter->report();

    expect($published)->toBe([]);
});

it('stays silent on a classic reading with no injected thread ceiling, but still primes', function (): void {
    $published = [];
    $cache = arrayCache();
    // Nothing honest to divide by — publishing against the scraped floor would lie.
    // This is also the Octane mid worker-reload shape (thread gauges, no worker total):
    // the endpoint answered, so the CPU fallback is armed either way.
    $reporter = burstReporter($cache, queuedScraper([ScrapeResult::threads(6, 1)]), nullCpu(), inFlightPeaking($cache, 6), $published);

    $reporter->report();

    expect($published)->toBe([]);
    expect($cache->get('yolo-burst:task-1:primed'))->not->toBeNull();
});

it('breaches on a CPU-corroborated scrape failure primed by a classic reading', function (): void {
    $published = [];
    $cache = arrayCache();
    $reporter = burstReporter($cache, queuedScraper([
        ScrapeResult::threads(2, 0), // primes (2 of 16, below floor → no publish) + seeds the CPU baseline
        ScrapeResult::failure(),     // scrape fails; CPU corroborates
    ]), queuedCpu(cpuRamp(100.0)), inFlightPeaking($cache, 0), $published, threadCeiling: 16);

    foreach (range(1, 2) as $ignored) {
        $cache->forget(WINDOW_KEY);
        $reporter->report();
    }

    expect($published)->toBe([100.0]);
    expect($cache->get('yolo-burst:task-1:ssr-bypass'))->not->toBeNull();
});

const SSR_BYPASS_KEY = 'yolo-burst:task-1:ssr-bypass';

it('flags the task saturated for SSR bypass when saturation trips the alarm threshold', function (): void {
    $published = [];
    $cache = arrayCache();
    $reporter = burstReporter($cache, queuedScraper([ScrapeResult::workers(4)]), nullCpu(), inFlightPeaking($cache, 3), $published); // 75%

    $reporter->report();

    expect($cache->get(SSR_BYPASS_KEY))->not->toBeNull();
    expect(WorkerSaturationReporter::ssrBypassKey('task-1'))->toBe(SSR_BYPASS_KEY);
});

it('does not flag SSR bypass for saturation below the alarm threshold', function (): void {
    $published = [];
    $cache = arrayCache();
    // 2 of 4 = 50%: publishes (≥ emit floor) but is below the 70% alarm threshold — no shed.
    $reporter = burstReporter($cache, queuedScraper([ScrapeResult::workers(4)]), nullCpu(), inFlightPeaking($cache, 2), $published);

    $reporter->report();

    expect($published)->toBe([50.0]);
    expect($cache->get(SSR_BYPASS_KEY))->toBeNull();
});

it('flags SSR bypass on a CPU-corroborated scrape-failure breach', function (): void {
    $published = [];
    $cache = arrayCache();
    $reporter = burstReporter($cache, queuedScraper([
        ScrapeResult::workers(4), // primes (25%, below floor) + seeds the CPU baseline
        ScrapeResult::failure(),  // scrape fails; high CPU corroborates → breach
    ]), queuedCpu(cpuRamp(100.0)), inFlightPeaking($cache, 1), $published);

    foreach (range(1, 2) as $ignored) {
        $cache->forget(WINDOW_KEY);
        $reporter->report();
    }

    expect($published)->toBe([100.0]);
    expect($cache->get(SSR_BYPASS_KEY))->not->toBeNull();
});
