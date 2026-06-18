<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\SyncWebBurstStep;

function burstManifest(bool $on): void
{
    // Burst is part of web autoscaling — "off" is an explicit `autoscaling: false`
    // web tier (no scalable target), which is what triggers a teardown.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => $on ? ['autoscaling' => ['min' => 2, 'max' => 8]] : ['autoscaling' => false]],
    ]);
}

function activeWebService(array &$captured): void
{
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'serviceArn' => 'arn']]])], $captured);
}

beforeEach(function (): void {
    burstManifest(on: true);
});

it('skips on a greenfield sync when the web service does not exist yet', function (): void {
    $ecs = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => []])], $ecs);

    expect((new SyncWebBurstStep())([]))->toBe(StepResult::SKIPPED);
});

it('creates the burst policy and alarm when burst is enabled', function (): void {
    $ecs = [];
    $aa = [];
    $cw = [];
    activeWebService($ecs);
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => []]),
        'PutScalingPolicy' => new Result(['PolicyARN' => 'arn:policy']),
    ], $aa);
    bindMockCloudWatchClient([
        'DescribeAlarms' => new Result(['MetricAlarms' => [['AlarmName' => 'yolo-testing-my-app-web-worker-saturation', 'AlarmArn' => 'arn']]]),
        'ListTagsForResource' => new Result(['Tags' => []]),
    ], $cw);

    expect((new SyncWebBurstStep())([]))->toBe(StepResult::CREATED);
    expect(collect($aa)->pluck('name'))->toContain('PutScalingPolicy');
    expect(collect($cw)->pluck('name'))->toContain('PutMetricAlarm');
});

it('would-create on a dry-run without writing', function (): void {
    $ecs = [];
    $aa = [];
    $cw = [];
    activeWebService($ecs);
    bindMockApplicationAutoScalingClient(['DescribeScalingPolicies' => new Result(['ScalingPolicies' => []])], $aa);
    bindMockCloudWatchClient(['DescribeAlarms' => new Result(['MetricAlarms' => []])], $cw);

    expect((new SyncWebBurstStep())(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE);
    expect(collect($aa)->pluck('name'))->not->toContain('PutScalingPolicy');
    expect(collect($cw)->pluck('name'))->not->toContain('PutMetricAlarm');
});

it('would-sync on a dry-run when an existing alarm has drifted', function (): void {
    // Both pieces already exist (so it's not a create), but the live alarm's threshold
    // differs from the desired — the step must surface that as WOULD_SYNC so the plan
    // (and the deploy gate's sync --check) treats it as a pending change, not clean.
    $ecs = [];
    $aa = [];
    $cw = [];
    activeWebService($ecs);
    bindMockApplicationAutoScalingClient(['DescribeScalingPolicies' => new Result(['ScalingPolicies' => [[
        'PolicyName' => 'yolo-testing-my-app-web-burst-policy',
        'PolicyARN' => 'arn:policy',
        'StepScalingPolicyConfiguration' => [
            'AdjustmentType' => 'ChangeInCapacity',
            'Cooldown' => 60,
            'MetricAggregationType' => 'Maximum',
            'StepAdjustments' => [
                ['MetricIntervalLowerBound' => 0.0, 'MetricIntervalUpperBound' => 10.0, 'ScalingAdjustment' => 1],
                ['MetricIntervalLowerBound' => 10.0, 'ScalingAdjustment' => 2],
            ],
        ],
    ]]])], $aa);
    bindMockCloudWatchClient(['DescribeAlarms' => new Result(['MetricAlarms' => [[
        'AlarmName' => 'yolo-testing-my-app-web-worker-saturation', 'AlarmArn' => 'arn',
        'Threshold' => 80.0, 'Period' => 10, 'EvaluationPeriods' => 1,
        'ComparisonOperator' => 'GreaterThanThreshold', 'Statistic' => 'Maximum',
    ]]])], $cw);

    expect((new SyncWebBurstStep())(['dry-run' => true]))->toBe(StepResult::WOULD_SYNC);
    expect(collect($cw)->pluck('name'))->not->toContain('PutMetricAlarm');
});

it('prunes the policy and alarm when burst is switched off', function (): void {
    burstManifest(on: false);

    $ecs = [];
    $aa = [];
    $cw = [];
    activeWebService($ecs);
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => [['PolicyName' => 'yolo-testing-my-app-web-burst-policy']]]),
        'DeleteScalingPolicy' => new Result([]),
    ], $aa);
    bindMockCloudWatchClient([
        'DescribeAlarms' => new Result(['MetricAlarms' => [['AlarmName' => 'yolo-testing-my-app-web-worker-saturation', 'AlarmArn' => 'arn']]]),
        'DeleteAlarms' => new Result([]),
    ], $cw);

    expect((new SyncWebBurstStep())([]))->toBe(StepResult::DELETED);
    expect(collect($aa)->pluck('name'))->toContain('DeleteScalingPolicy');
    expect(collect($cw)->pluck('name'))->toContain('DeleteAlarms');
});

it('skips when burst is off and there is nothing to tear down', function (): void {
    burstManifest(on: false);

    $ecs = [];
    $aa = [];
    $cw = [];
    activeWebService($ecs);
    bindMockApplicationAutoScalingClient(['DescribeScalingPolicies' => new Result(['ScalingPolicies' => []])], $aa);
    bindMockCloudWatchClient(['DescribeAlarms' => new Result(['MetricAlarms' => []])], $cw);

    expect((new SyncWebBurstStep())([]))->toBe(StepResult::SKIPPED);
    expect(collect($aa)->pluck('name'))->not->toContain('DeleteScalingPolicy');
});
