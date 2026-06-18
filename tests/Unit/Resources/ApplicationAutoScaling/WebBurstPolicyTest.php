<?php

use Aws\Result;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\WebBurstPolicy;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => ['min' => 2, 'max' => 8]]],
    ]);
});

it('keeps the burst threshold reachable across the realistic worker-pool range', function (int $workers): void {
    // Saturation quantises to busy/total, so a loaded pool with all-but-one worker busy
    // reads (N-1)/N — the hardest case to clear is the smallest pool (4 workers → 75%).
    // The alarm uses a strict `>` comparator, so that reading must exceed the threshold
    // for burst to trip without a sustained full-pool pin (the bug the old 80 caused).
    $loadedSaturation = ($workers - 1) / $workers * 100;

    expect($loadedSaturation)->toBeGreaterThan((float) WebBurstPolicy::ALARM_THRESHOLD);

    // The emit floor sits below the threshold, so the alarm is fed a not-breaching
    // datapoint on the step just under the trip as load ramps.
    expect((float) WebBurstPolicy::EMIT_FLOOR)->toBeLessThan((float) WebBurstPolicy::ALARM_THRESHOLD);
})->with([4, 8, 12, 16]);

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

it('creates a scale-out step policy and a high-resolution saturation alarm when applying', function (): void {
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

    // A scale-out-only StepScaling policy on the WEB target: ≥70% (the alarm
    // threshold) → +1, ≥80% → +2. Bounds are relative to the threshold.
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

    // A high-resolution (10s period) alarm on the EMF worker-saturation metric,
    // dimensioned by this app's web service, firing the step policy.
    $alarm = collect($cw)->firstWhere('name', 'PutMetricAlarm');
    expect($alarm['args'])->toMatchArray([
        'Namespace' => 'YOLO/Autoscaling',
        'MetricName' => 'WorkerSaturation',
        'Period' => 10,
        'EvaluationPeriods' => 1,
        'Threshold' => 70,
        'Statistic' => 'Maximum',
        'ComparisonOperator' => 'GreaterThanThreshold',
        'TreatMissingData' => 'notBreaching',
    ]);
    expect($alarm['args']['Dimensions'])->toBe([['Name' => 'ServiceName', 'Value' => 'yolo-testing-my-app-web']]);
    expect($alarm['args']['AlarmActions'])->toBe([$policyArn]);
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
