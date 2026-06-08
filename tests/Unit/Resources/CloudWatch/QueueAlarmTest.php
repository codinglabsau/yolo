<?php

use Aws\Result;
use Aws\MockHandler;
use Aws\Sns\SnsClient;
use Aws\CommandInterface;
use Codinglabs\Yolo\Helpers;
use GuzzleHttp\Promise\Create;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\Solo\SyncQueueAlarmStep;

/** Bind an SNS client whose ListTopics always returns the alarm topic. */
function bindAlarmTopic(): void
{
    $result = new Result([
        'Topics' => [
            ['TopicArn' => 'arn:aws:sns:ap-southeast-2:111111111111:yolo-testing'],
        ],
    ]);

    $mock = new class($result) extends MockHandler
    {
        public function __construct(protected Result $result) {}

        public function __invoke(CommandInterface $cmd, $request)
        {
            return Create::promiseFor($this->result);
        }
    };

    Helpers::app()->instance('sns', new SnsClient([
        'region' => 'ap-southeast-2',
        'version' => 'latest',
        'credentials' => false,
        'handler' => $mock,
    ]));
}

/** A live alarm whose attributes match the solo step's desired state. */
function inSyncQueueAlarm(array $overrides = []): array
{
    return array_merge([
        'AlarmName' => 'yolo-testing-my-app-queue-depth-alarm',
        'AlarmArn' => 'arn:aws:cloudwatch:ap-southeast-2:111111111111:alarm:yolo-testing-my-app-queue-depth-alarm',
        'ComparisonOperator' => 'GreaterThanThreshold',
        'EvaluationPeriods' => 3,
        'Period' => 300,
        'Statistic' => 'Average',
        'Threshold' => 100.0,
        'TreatMissingData' => 'notBreaching',
        'AlarmActions' => ['arn:aws:sns:ap-southeast-2:111111111111:yolo-testing'],
        'OKActions' => ['arn:aws:sns:ap-southeast-2:111111111111:yolo-testing'],
    ], $overrides);
}

/** The ownership tags a synced alarm carries. */
function syncedAlarmTags(): array
{
    return [
        ['Key' => 'yolo:environment', 'Value' => 'testing'],
        ['Key' => 'yolo:scope', 'Value' => 'app'],
        ['Key' => 'yolo:app', 'Value' => 'my-app'],
        ['Key' => 'Name', 'Value' => 'yolo-testing-my-app-queue-depth-alarm'],
    ];
}

beforeEach(function () {
    writeManifest(['region' => 'ap-southeast-2', 'account-id' => '111111111111']);
    bindAlarmTopic();
});

it('creates the alarm when it does not yet exist', function () {
    $captured = [];
    bindMockCloudWatchClient([
        'DescribeAlarms' => new Result(['MetricAlarms' => []]),
    ], $captured);

    expect((new SyncQueueAlarmStep())([]))->toBe(StepResult::CREATED);

    $put = collect($captured)->firstWhere('name', 'PutMetricAlarm');
    expect($put)->not->toBeNull();
    expect($put['args']['AlarmName'])->toBe('yolo-testing-my-app-queue-depth-alarm');
    // Tags ride along on the create (PutMetricAlarm only honours them on create).
    expect($put['args'])->toHaveKey('Tags');
});

it('records config drift on the plan pass and re-puts the alarm on apply', function () {
    $captured = [];
    bindMockCloudWatchClient([
        // Live threshold has drifted from the desired 100.
        'DescribeAlarms' => new Result(['MetricAlarms' => [inSyncQueueAlarm(['Threshold' => 999.0])]]),
        'ListTagsForResource' => new Result(['Tags' => syncedAlarmTags()]),
    ], $captured);

    // Plan (dry-run) pass: drift is surfaced as a recorded change without writing.
    $plan = new SyncQueueAlarmStep();
    expect($plan(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect(collect($plan->changes())->pluck('attribute'))->toContain('threshold');
    expect(array_column($captured, 'name'))->not->toContain('PutMetricAlarm');

    // Apply pass: the alarm is re-put.
    $captured = [];
    bindMockCloudWatchClient([
        'DescribeAlarms' => new Result(['MetricAlarms' => [inSyncQueueAlarm(['Threshold' => 999.0])]]),
        'ListTagsForResource' => new Result(['Tags' => syncedAlarmTags()]),
    ], $captured);

    expect((new SyncQueueAlarmStep())([]))->toBe(StepResult::SYNCED);
    expect(array_column($captured, 'name'))->toContain('PutMetricAlarm');
});

it('records no change and never re-puts when the alarm is already in sync', function () {
    // The acceptance criterion: a clean alarm produces no pending entry, so a
    // no-op `yolo sync` can short-circuit to "Already in sync" instead of forever
    // tripping the confirm gate.
    $captured = [];
    bindMockCloudWatchClient([
        'DescribeAlarms' => new Result(['MetricAlarms' => [inSyncQueueAlarm()]]),
        'ListTagsForResource' => new Result(['Tags' => syncedAlarmTags()]),
    ], $captured);

    $step = new SyncQueueAlarmStep();
    expect($step(['dry-run' => true]))->toBe(StepResult::SYNCED);
    expect($step->changes())->toBeEmpty();
    expect(array_column($captured, 'name'))
        ->not->toContain('PutMetricAlarm')
        ->not->toContain('TagResource');
});

it('back-fills missing ownership tags without re-putting an in-sync alarm', function () {
    // PutMetricAlarm can't tag an existing alarm, so tag drift is healed via
    // TagResource — and an alarm whose config is in sync is not needlessly re-put.
    $captured = [];
    bindMockCloudWatchClient([
        'DescribeAlarms' => new Result(['MetricAlarms' => [inSyncQueueAlarm()]]),
        'ListTagsForResource' => new Result(['Tags' => []]),
    ], $captured);

    expect((new SyncQueueAlarmStep())([]))->toBe(StepResult::SYNCED);

    $tag = collect($captured)->firstWhere('name', 'TagResource');
    expect($tag)->not->toBeNull();
    expect($tag['args']['ResourceARN'])->toBe('arn:aws:cloudwatch:ap-southeast-2:111111111111:alarm:yolo-testing-my-app-queue-depth-alarm');

    $tags = collect($tag['args']['Tags'])->mapWithKeys(fn ($t) => [$t['Key'] => $t['Value']]);
    expect($tags)->toMatchArray([
        'yolo:environment' => 'testing',
        'yolo:scope' => 'app',
        'yolo:app' => 'my-app',
        'Name' => 'yolo-testing-my-app-queue-depth-alarm',
    ]);

    // Config is in sync, so the alarm itself is not re-put.
    expect(array_column($captured, 'name'))->not->toContain('PutMetricAlarm');
});
