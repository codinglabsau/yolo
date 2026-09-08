<?php

use Aws\Result;
use Codinglabs\Yolo\Change;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\WebBurstPolicy;

function writeBurstManifest(bool $octane = true): void
{
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => ['min' => 2, 'max' => 8], 'octane' => $octane]],
    ]);
}

beforeEach(function (): void {
    writeBurstManifest();
});

it('keeps the classic burst threshold reachable across the realistic thread-ceiling range', function (int $threads): void {
    // Classic saturation quantises to (busy + queued)/ceiling, so a loaded ceiling with
    // all-but-one thread busy reads (N-1)/N — the hardest case to clear is the smallest
    // ceiling (4 → 75%). The alarm uses a strict `>` comparator, so that reading must
    // exceed the threshold for burst to trip without a sustained full pin.
    $loadedSaturation = ($threads - 1) / $threads * 100;

    expect($loadedSaturation)->toBeGreaterThan((float) WebBurstPolicy::alarmThreshold(octane: false));
})->with([4, 8, 12, 16]);

it('keeps the Octane burst threshold out of reach of anything short of a queue', function (int $workers): void {
    // `busy_workers` counts every dispatched request, load-balancer probes included, so
    // a pool with every worker busy but nothing waiting reads exactly 100 — a state two
    // or three probes coinciding with idle traffic produces on a small pool. With the
    // strict `>` comparator that must not trip; the first queued request must.
    $fullPool = $workers / $workers * 100;
    $oneQueued = ($workers + 1) / $workers * 100;

    expect($fullPool)->not->toBeGreaterThan((float) WebBurstPolicy::alarmThreshold(octane: true));
    expect($oneQueued)->toBeGreaterThan((float) WebBurstPolicy::alarmThreshold(octane: true));
})->with([1, 4, 8, 16, 32]);

it('keeps the emit floor under both tiers\' thresholds so the alarm sees a not-breaching datapoint first', function (): void {
    expect((float) WebBurstPolicy::EMIT_FLOOR)
        ->toBeLessThan((float) WebBurstPolicy::alarmThreshold(octane: true))
        ->toBeLessThan((float) WebBurstPolicy::alarmThreshold(octane: false));
});

it('reports both pieces as pending on a dry-run without writing', function (): void {
    $aa = [];
    $cw = [];
    bindMockApplicationAutoScalingClient(['DescribeScalingPolicies' => new Result(['ScalingPolicies' => []])], $aa);
    bindMockCloudWatchClient(['DescribeAlarms' => new Result(['MetricAlarms' => []])], $cw);

    $changes = (new WebBurstPolicy())->synchronise(apply: false);

    expect($changes)->toHaveCount(2);
    expect(collect($aa)->pluck('name'))->not->toContain('PutScalingPolicy');
    expect(collect($cw)->pluck('name'))->not->toContain('PutMetricAlarm');
});

it('creates a scale-out step policy and a high-resolution saturation alarm when applying', function (bool $octane, int $threshold): void {
    writeBurstManifest($octane);

    $policyArn = 'arn:aws:autoscaling:ap-southeast-2:111111111111:scalingPolicy:x:resource/ecs/service/yolo-testing-my-app/yolo-testing-my-app-web:policyName/burst';

    $aa = [];
    $cw = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => []]),
        'PutScalingPolicy' => new Result(['PolicyARN' => $policyArn]),
    ], $aa);
    bindMockCloudWatchClient([
        'DescribeAlarms' => new Result(['MetricAlarms' => [
            ['AlarmName' => 'yolo-testing-my-app-web-worker-saturation', 'AlarmArn' => 'arn:aws:cloudwatch:ap-southeast-2:111111111111:alarm:yolo-testing-my-app-web-worker-saturation'],
        ]]),
        'ListTagsForResource' => new Result(['Tags' => []]),
    ], $cw);

    (new WebBurstPolicy())->synchronise(apply: true);

    // A scale-out-only StepScaling policy on the WEB target: up to 10 over the alarm
    // threshold → +1, beyond → +2. Bounds are relative to the threshold, so the same
    // step config serves both tiers' lines.
    $put = collect($aa)->firstWhere('name', 'PutScalingPolicy');
    expect($put['args'])->toMatchArray([
        'PolicyType' => 'StepScaling',
        'ResourceId' => 'service/yolo-testing-my-app/yolo-testing-my-app-web',
    ]);
    $stepConfig = $put['args']['StepScalingPolicyConfiguration'];
    expect($stepConfig)->toMatchArray([
        'AdjustmentType' => 'ChangeInCapacity',
        'MetricAggregationType' => 'Maximum',
    ]);
    expect($stepConfig['StepAdjustments'])->toBe([
        ['MetricIntervalLowerBound' => 0, 'MetricIntervalUpperBound' => 10, 'ScalingAdjustment' => 1],
        ['MetricIntervalLowerBound' => 10, 'ScalingAdjustment' => 2],
    ]);

    // A high-resolution (10s period) alarm on the worker-saturation metric, dimensioned
    // by this app's web service, firing the step policy. Two consecutive breaching
    // datapoints, so one window where probes and requests coincide can't buy a task;
    // the line itself is the tier's own.
    $alarm = collect($cw)->firstWhere('name', 'PutMetricAlarm');
    expect($alarm['args'])->toMatchArray([
        'Namespace' => 'YOLO/Autoscaling',
        'MetricName' => 'WorkerSaturation',
        'Period' => 10,
        'EvaluationPeriods' => 2,
        'DatapointsToAlarm' => 2,
        'Threshold' => $threshold,
        'Statistic' => 'Maximum',
        'ComparisonOperator' => 'GreaterThanThreshold',
        'TreatMissingData' => 'notBreaching',
    ]);
    expect($alarm['args']['Dimensions'])->toBe([['Name' => 'ServiceName', 'Value' => 'yolo-testing-my-app-web']]);
    expect($alarm['args']['AlarmActions'])->toBe([$policyArn]);
})->with([
    'octane' => [true, 100],
    'classic' => [false, 70],
]);

/**
 * A live policy + alarm that already match the desired definition — the fixture for
 * the drift cases below. The alarm's threshold is overridable so a test can drift it.
 *
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function inSyncBurstState(float $threshold = 100.0, int $evaluationPeriods = 2, int $datapointsToAlarm = 2): array
{
    $policy = [
        'PolicyName' => 'yolo-testing-my-app-web-burst-policy',
        'PolicyARN' => 'arn:policy',
        'StepScalingPolicyConfiguration' => [
            'AdjustmentType' => 'ChangeInCapacity',
            'Cooldown' => 60,
            'MetricAggregationType' => 'Maximum',
            // AWS echoes the bounds back as floats and omits the open-ended upper bound.
            'StepAdjustments' => [
                ['MetricIntervalLowerBound' => 0.0, 'MetricIntervalUpperBound' => 10.0, 'ScalingAdjustment' => 1],
                ['MetricIntervalLowerBound' => 10.0, 'ScalingAdjustment' => 2],
            ],
        ],
    ];

    $alarm = [
        'AlarmName' => 'yolo-testing-my-app-web-worker-saturation',
        'AlarmArn' => 'arn:alarm',
        'Threshold' => $threshold,
        'Period' => 10,
        'EvaluationPeriods' => $evaluationPeriods,
        'DatapointsToAlarm' => $datapointsToAlarm,
        'ComparisonOperator' => 'GreaterThanThreshold',
        'Statistic' => 'Maximum',
    ];

    return [$policy, $alarm];
}

it('re-puts the alarm at the new threshold when the live alarm has drifted', function (): void {
    // The exact regression: moving a tier's threshold in code must reach an alarm that
    // already exists. The live Octane alarm is at the old 70; sync must re-put it at
    // 100 — without re-putting the unchanged policy (its ARN is stable, so the alarm
    // reuses it).
    [$policy, $alarm] = inSyncBurstState(threshold: 70.0);

    $aa = [];
    $cw = [];
    bindMockApplicationAutoScalingClient(['DescribeScalingPolicies' => new Result(['ScalingPolicies' => [$policy]])], $aa);
    bindMockCloudWatchClient([
        'DescribeAlarms' => new Result(['MetricAlarms' => [$alarm]]),
        'ListTagsForResource' => new Result(['Tags' => []]),
    ], $cw);

    $changes = (new WebBurstPolicy())->synchronise(apply: true);

    expect($changes)->toHaveCount(1);
    expect($changes[0]->describe())->toBe('web burst alarm Threshold: 70 → 100');

    $put = collect($cw)->firstWhere('name', 'PutMetricAlarm');
    expect($put['args']['Threshold'])->toBe(100);
    expect($put['args']['AlarmActions'])->toBe(['arn:policy']);
    expect(collect($aa)->pluck('name'))->not->toContain('PutScalingPolicy');
});

it('re-puts a single-datapoint alarm as two consecutive datapoints, reconciling DatapointsToAlarm in place', function (): void {
    // An alarm provisioned before the two-datapoint shape has EvaluationPeriods 1 and
    // no DatapointsToAlarm at all (CloudWatch omits it when unset). Both must read as
    // drift and be re-put together — if DatapointsToAlarm weren't part of the compared
    // behaviour, the first sync would write it and every later plan would still read
    // the live alarm as in sync while an operator's manual edit of it went unnoticed.
    [$policy, $alarm] = inSyncBurstState(evaluationPeriods: 1);
    unset($alarm['DatapointsToAlarm']);

    $aa = [];
    $cw = [];
    bindMockApplicationAutoScalingClient(['DescribeScalingPolicies' => new Result(['ScalingPolicies' => [$policy]])], $aa);
    bindMockCloudWatchClient([
        'DescribeAlarms' => new Result(['MetricAlarms' => [$alarm]]),
        'ListTagsForResource' => new Result(['Tags' => []]),
    ], $cw);

    $changes = (new WebBurstPolicy())->synchronise(apply: true);

    expect(array_map(fn (Change $change): string => $change->describe(), $changes))->toBe([
        'web burst alarm EvaluationPeriods: 1 → 2',
        'web burst alarm DatapointsToAlarm: <absent> → 2',
    ]);

    $put = collect($cw)->firstWhere('name', 'PutMetricAlarm');
    expect($put['args'])->toMatchArray(['EvaluationPeriods' => 2, 'DatapointsToAlarm' => 2]);
});

it('keeps the classic tier on its own line — a classic sync never carries the Octane threshold', function (): void {
    writeBurstManifest(octane: false);
    [$policy, $alarm] = inSyncBurstState(threshold: 70.0);

    $aa = [];
    $cw = [];
    bindMockApplicationAutoScalingClient(['DescribeScalingPolicies' => new Result(['ScalingPolicies' => [$policy]])], $aa);
    bindMockCloudWatchClient(['DescribeAlarms' => new Result(['MetricAlarms' => [$alarm]])], $cw);

    expect((new WebBurstPolicy())->synchronise(apply: true))->toBe([]);
    expect(collect($cw)->pluck('name'))->not->toContain('PutMetricAlarm');
});

it('is a no-op when the live policy and alarm already match — no false drift', function (): void {
    // The dangerous failure mode of a config reconciler: an equal config that reads as
    // drift would make every deploy gate refuse and never converge. A matching live
    // state must produce zero changes and zero writes.
    [$policy, $alarm] = inSyncBurstState();

    $aa = [];
    $cw = [];
    bindMockApplicationAutoScalingClient(['DescribeScalingPolicies' => new Result(['ScalingPolicies' => [$policy]])], $aa);
    bindMockCloudWatchClient(['DescribeAlarms' => new Result(['MetricAlarms' => [$alarm]])], $cw);

    $changes = (new WebBurstPolicy())->synchronise(apply: true);

    expect($changes)->toBe([]);
    expect(collect($aa)->pluck('name'))->not->toContain('PutScalingPolicy');
    expect(collect($cw)->pluck('name'))->not->toContain('PutMetricAlarm');
});

it('tears down the policy and its self-authored alarm', function (): void {
    $aa = [];
    $cw = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => [['PolicyName' => 'yolo-testing-my-app-web-burst-policy']]]),
        'DeleteScalingPolicy' => new Result([]),
    ], $aa);
    bindMockCloudWatchClient([
        'DescribeAlarms' => new Result(['MetricAlarms' => [['AlarmName' => 'yolo-testing-my-app-web-worker-saturation', 'AlarmArn' => 'arn']]]),
        'DeleteAlarms' => new Result([]),
    ], $cw);

    $changes = (new WebBurstPolicy())->teardown(apply: true);

    expect($changes)->toHaveCount(2);
    expect(collect($aa)->firstWhere('name', 'DeleteScalingPolicy')['args']['PolicyName'])->toBe('yolo-testing-my-app-web-burst-policy');
    expect(collect($cw)->firstWhere('name', 'DeleteAlarms')['args']['AlarmNames'])->toBe(['yolo-testing-my-app-web-worker-saturation']);
});

it('deletes only the standalone alarm when the step policy was already cascaded', function (): void {
    // The real teardown ordering when autoscaling is removed entirely:
    // SyncScalableTargetStep deregisters the target first, which AWS cascades to
    // delete the step policy — so teardown finds the policy already gone and must
    // still delete the self-authored alarm (it isn't policy-generated, so the
    // cascade never touches it).
    $aa = [];
    $cw = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => []]),
        'DeleteScalingPolicy' => new Result([]),
    ], $aa);
    bindMockCloudWatchClient([
        'DescribeAlarms' => new Result(['MetricAlarms' => [['AlarmName' => 'yolo-testing-my-app-web-worker-saturation', 'AlarmArn' => 'arn']]]),
        'DeleteAlarms' => new Result([]),
    ], $cw);

    $changes = (new WebBurstPolicy())->teardown(apply: true);

    expect($changes)->toHaveCount(1);
    expect(collect($aa)->pluck('name'))->not->toContain('DeleteScalingPolicy');
    expect(collect($cw)->firstWhere('name', 'DeleteAlarms')['args']['AlarmNames'])->toBe(['yolo-testing-my-app-web-worker-saturation']);
});

it('teardown is a no-op when neither the policy nor the alarm exists', function (): void {
    $aa = [];
    $cw = [];
    bindMockApplicationAutoScalingClient(['DescribeScalingPolicies' => new Result(['ScalingPolicies' => []])], $aa);
    bindMockCloudWatchClient(['DescribeAlarms' => new Result(['MetricAlarms' => []])], $cw);

    $changes = (new WebBurstPolicy())->teardown(apply: true);

    expect($changes)->toBe([]);
    expect(collect($aa)->pluck('name'))->not->toContain('DeleteScalingPolicy');
    expect(collect($cw)->pluck('name'))->not->toContain('DeleteAlarms');
});
