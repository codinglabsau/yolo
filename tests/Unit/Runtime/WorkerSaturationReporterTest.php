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

function burstReporter(Repository $cache, Scraper $scraper, Cpu $cpu, array &$published): WorkerSaturationReporter
{
    return new WorkerSaturationReporter($cache, recordingCloudWatch($published), $scraper, $cpu, 'svc', 'task-1');
}

/** Two snapshots a window apart whose delta is the given CPU % of a 0.5-core task. */
function cpuRamp(float $percent): array
{
    $wallMicros = 1_000_000;
    $cores = 0.5;
    $usedMicros = (int) ($percent / 100 * $wallMicros * $cores);

    return [new CpuSnapshot(0, 0, $cores), new CpuSnapshot($usedMicros, $wallMicros, $cores)];
}

it('publishes a reading at or above the emit floor', function (): void {
    $published = [];
    $reporter = burstReporter(arrayCache(), queuedScraper([ScrapeResult::reading(75.0)]), nullCpu(), $published);

    $reporter->report();

    expect($published)->toBe([75.0]);
});

it('stays silent for a reading below the emit floor', function (): void {
    $published = [];
    $reporter = burstReporter(arrayCache(), queuedScraper([ScrapeResult::reading(25.0)]), nullCpu(), $published);

    $reporter->report();

    expect($published)->toBe([]);
});

it('does real work at most once per window no matter the request rate', function (): void {
    $published = [];
    $reporter = burstReporter(arrayCache(), queuedScraper([ScrapeResult::reading(75.0), ScrapeResult::reading(90.0)]), nullCpu(), $published);

    $reporter->report();
    $reporter->report(); // window still claimed → no scrape, no publish

    expect($published)->toBe([75.0]);
});

it('stays silent when metrics are absent (off / classic mode)', function (): void {
    $published = [];
    $reporter = burstReporter(arrayCache(), queuedScraper([ScrapeResult::absent()]), nullCpu(), $published);

    $reporter->report();

    expect($published)->toBe([]);
});

it('never breaches on a failure before it has been primed by a success — even at high CPU', function (): void {
    $published = [];
    $cache = arrayCache();
    $reporter = burstReporter($cache, queuedScraper([ScrapeResult::failure(), ScrapeResult::failure()]), queuedCpu(cpuRamp(100.0)), $published);

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
        ScrapeResult::reading(40.0), // primes (below floor → no publish) and seeds the CPU baseline
        ScrapeResult::failure(),     // scrape fails; CPU corroborates
    ]), queuedCpu(cpuRamp(100.0)), $published);

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
        ScrapeResult::reading(40.0),
        ScrapeResult::failure(),
    ]), queuedCpu(cpuRamp(20.0)), $published);

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
        ScrapeResult::reading(40.0),
        ScrapeResult::failure(),
    ]), queuedCpu([new CpuSnapshot(0, 0, 0.5)]), $published); // no second snapshot → null on the failure window

    foreach (range(1, 2) as $ignored) {
        $cache->forget(WINDOW_KEY);
        $reporter->report();
    }

    expect($published)->toBe([]);
});

const SSR_BYPASS_KEY = 'yolo-burst:task-1:ssr-bypass';

it('flags the task saturated for SSR bypass when a reading trips the alarm threshold', function (): void {
    $published = [];
    $cache = arrayCache();
    $reporter = burstReporter($cache, queuedScraper([ScrapeResult::reading(75.0)]), nullCpu(), $published);

    $reporter->report();

    expect($cache->get(SSR_BYPASS_KEY))->not->toBeNull();
    expect(WorkerSaturationReporter::ssrBypassKey('task-1'))->toBe(SSR_BYPASS_KEY);
});

it('does not flag SSR bypass for a reading below the alarm threshold', function (): void {
    $published = [];
    $cache = arrayCache();
    // 60 publishes (≥ emit floor) but is below the 70 alarm threshold — no shed.
    $reporter = burstReporter($cache, queuedScraper([ScrapeResult::reading(60.0)]), nullCpu(), $published);

    $reporter->report();

    expect($published)->toBe([60.0]);
    expect($cache->get(SSR_BYPASS_KEY))->toBeNull();
});

it('flags SSR bypass on a CPU-corroborated scrape-failure breach', function (): void {
    $published = [];
    $cache = arrayCache();
    $reporter = burstReporter($cache, queuedScraper([
        ScrapeResult::reading(40.0), // primes + seeds the CPU baseline
        ScrapeResult::failure(),     // scrape fails; high CPU corroborates → breach
    ]), queuedCpu(cpuRamp(100.0)), $published);

    foreach (range(1, 2) as $ignored) {
        $cache->forget(WINDOW_KEY);
        $reporter->report();
    }

    expect($published)->toBe([100.0]);
    expect($cache->get(SSR_BYPASS_KEY))->not->toBeNull();
});
