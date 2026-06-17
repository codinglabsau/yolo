<?php

use Aws\Result;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\QueueScaleToZeroBootstrap;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => ['autoscaling' => ['min' => 0]]],
    ]);
});

it('reports both pieces as pending on a dry-run without writing', function (): void {
    $aa = [];
    $cw = [];
    bindMockApplicationAutoScalingClient(['DescribeScalingPolicies' => new Result(['ScalingPolicies' => []])], $aa);
    bindMockCloudWatchClient(['DescribeAlarms' => new Result(['MetricAlarms' => []])], $cw);

    $changes = (new QueueScaleToZeroBootstrap())->synchronise(apply: false);

    expect($changes)->toHaveCount(2);
    expect(collect($aa)->pluck('name'))->not->toContain('PutScalingPolicy');
    expect(collect($cw)->pluck('name'))->not->toContain('PutMetricAlarm');
});

it('sets the queue to exactly one task when a message arrives at zero', function (): void {
    $alarmArn = 'arn:aws:cloudwatch:ap-southeast-2:111111111111:alarm:yolo-testing-my-app-queue-has-messages';
    $policyArn = 'arn:aws:autoscaling:ap-southeast-2:111111111111:scalingPolicy:x:resource/ecs/service/yolo-testing-my-app/yolo-testing-my-app-queue:policyName/bootstrap';

    $aa = [];
    $cw = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => []]),
        'PutScalingPolicy' => new Result(['PolicyARN' => $policyArn]),
    ], $aa);
    bindMockCloudWatchClient([
        'DescribeAlarms' => new Result(['MetricAlarms' => [
            ['AlarmName' => 'yolo-testing-my-app-queue-has-messages', 'AlarmArn' => $alarmArn],
        ]]),
        'ListTagsForResource' => new Result(['Tags' => []]),
    ], $cw);

    (new QueueScaleToZeroBootstrap())->synchronise(apply: true);

    // A StepScaling policy that asserts ExactCapacity 1 — never fights the backlog
    // policy's higher number (App Auto Scaling takes the max), just breaks zero.
    $put = collect($aa)->firstWhere('name', 'PutScalingPolicy');
    expect($put['args']['PolicyType'])->toBe('StepScaling');
    expect($put['args']['StepScalingPolicyConfiguration']['AdjustmentType'])->toBe('ExactCapacity');
    expect($put['args']['StepScalingPolicyConfiguration']['StepAdjustments'][0]['ScalingAdjustment'])->toBe(1);

    // The alarm fires the moment a message is visible (> 0) and points at the policy.
    $alarm = collect($cw)->firstWhere('name', 'PutMetricAlarm');
    expect($alarm['args'])->toMatchArray([
        'MetricName' => 'ApproximateNumberOfMessagesVisible',
        'Namespace' => 'AWS/SQS',
        'Threshold' => 0,
        'ComparisonOperator' => 'GreaterThanThreshold',
    ]);
    expect($alarm['args']['AlarmActions'])->toBe([$policyArn]);
});
