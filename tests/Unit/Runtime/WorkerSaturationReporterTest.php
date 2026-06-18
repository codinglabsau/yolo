<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\CommandInterface;
use GuzzleHttp\Promise\Create;
use Aws\CloudWatch\CloudWatchClient;
use Codinglabs\Yolo\Runtime\CpuSnapshot;
use Codinglabs\Yolo\Runtime\ScrapeResult;
use Codinglabs\Yolo\Runtime\Contracts\Cpu;
use Codinglabs\Yolo\Runtime\Contracts\Scraper;
use Codinglabs\Yolo\Runtime\Contracts\WindowStore;
use Codinglabs\Yolo\Runtime\WorkerSaturationReporter;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\WebBurstPolicy;

/** The window key the reporter claims for task 'task-1' — forget it to simulate the next window. */
const WINDOW_KEY = 'yolo-burst:task-1:window';

function fakeWindowStore(): WindowStore
{
    return new class() implements WindowStore
    {
        /** @var array<string, int> */
        public array $data = [];

        public function add(string $key, int $ttlSeconds): bool
        {
            if (array_key_exists($key, $this->data)) {
                return false;
            }

            $this->data[$key] = 1;

            return true;
        }

        public function get(string $key): ?int
        {
            return $this->data[$key] ?? null;
        }

        public function put(string $key, int $value, int $ttlSeconds): void
        {
            $this->data[$key] = $value;
        }

        public function forget(string $key): void
        {
            unset($this->data[$key]);
        }
    };
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

function burstReporter(WindowStore $store, Scraper $scraper, Cpu $cpu, array &$published): WorkerSaturationReporter
{
    return new WorkerSaturationReporter($store, recordingCloudWatch($published), $scraper, $cpu, 'svc', 'task-1');
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
    $reporter = burstReporter(fakeWindowStore(), queuedScraper([ScrapeResult::reading(75.0)]), nullCpu(), $published);

    $reporter->report();

    expect($published)->toBe([75.0]);
});

it('stays silent for a reading below the emit floor', function (): void {
    $published = [];
    $reporter = burstReporter(fakeWindowStore(), queuedScraper([ScrapeResult::reading(25.0)]), nullCpu(), $published);

    $reporter->report();

    expect($published)->toBe([]);
});

it('does real work at most once per window no matter the request rate', function (): void {
    $published = [];
    $store = fakeWindowStore();
    $reporter = burstReporter($store, queuedScraper([ScrapeResult::reading(75.0), ScrapeResult::reading(90.0)]), nullCpu(), $published);

    $reporter->report();
    $reporter->report(); // window still claimed → no scrape, no publish

    expect($published)->toBe([75.0]);
});

it('stays silent when metrics are absent (off / classic mode)', function (): void {
    $published = [];
    $reporter = burstReporter(fakeWindowStore(), queuedScraper([ScrapeResult::absent()]), nullCpu(), $published);

    $reporter->report();

    expect($published)->toBe([]);
});

it('never breaches on a failure before it has been primed by a success — even at high CPU', function (): void {
    $published = [];
    $store = fakeWindowStore();
    $reporter = burstReporter($store, queuedScraper([ScrapeResult::failure(), ScrapeResult::failure()]), queuedCpu(cpuRamp(100.0)), $published);

    foreach (range(1, 2) as $ignored) {
        $store->forget(WINDOW_KEY);
        $reporter->report();
    }

    expect($published)->toBe([]);
});

it('breaches with a tripping value when a primed scrape fails and CPU is high', function (): void {
    $published = [];
    $store = fakeWindowStore();
    $reporter = burstReporter($store, queuedScraper([
        ScrapeResult::reading(40.0), // primes (below floor → no publish) and seeds the CPU baseline
        ScrapeResult::failure(),     // scrape fails; CPU corroborates
    ]), queuedCpu(cpuRamp(100.0)), $published);

    foreach (range(1, 2) as $ignored) {
        $store->forget(WINDOW_KEY);
        $reporter->report();
    }

    expect($published)->toBe([100.0]);
    expect((float) WebBurstPolicy::ALARM_THRESHOLD)->toBeLessThan(100.0);
});

it('stays silent when a primed scrape fails but CPU is low (a transient, not a pin)', function (): void {
    $published = [];
    $store = fakeWindowStore();
    $reporter = burstReporter($store, queuedScraper([
        ScrapeResult::reading(40.0),
        ScrapeResult::failure(),
    ]), queuedCpu(cpuRamp(20.0)), $published);

    foreach (range(1, 2) as $ignored) {
        $store->forget(WINDOW_KEY);
        $reporter->report();
    }

    expect($published)->toBe([]);
});

it('stays silent when a primed scrape fails and CPU cannot be read', function (): void {
    $published = [];
    $store = fakeWindowStore();
    $reporter = burstReporter($store, queuedScraper([
        ScrapeResult::reading(40.0),
        ScrapeResult::failure(),
    ]), queuedCpu([new CpuSnapshot(0, 0, 0.5)]), $published); // no second snapshot → null on the failure window

    foreach (range(1, 2) as $ignored) {
        $store->forget(WINDOW_KEY);
        $reporter->report();
    }

    expect($published)->toBe([]);
});
