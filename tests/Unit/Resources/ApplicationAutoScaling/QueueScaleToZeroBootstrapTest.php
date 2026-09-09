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

it('reports the alarm in sync when it already watches the manifest queue set', function (): void {
    $aa = [];
    $cw = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => [['PolicyName' => 'yolo-testing-my-app-queue-bootstrap-policy']]]),
    ], $aa);
    bindMockCloudWatchClient([
        'DescribeAlarms' => new Result(['MetricAlarms' => [[
            'AlarmName' => 'yolo-testing-my-app-queue-has-messages',
            'Dimensions' => [['Name' => 'QueueName', 'Value' => 'yolo-testing-my-app']],
        ]]]),
    ], $cw);

    expect((new QueueScaleToZeroBootstrap())->synchronise(apply: true))->toBe([]);
    expect(collect($aa)->pluck('name'))->not->toContain('PutScalingPolicy');
    expect(collect($cw)->pluck('name'))->not->toContain('PutMetricAlarm');
});

it('alarms on the summed tenant and landlord queues under dedicated isolation', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => ['autoscaling' => ['min' => 0]]],
        'multitenancy' => ['queue-isolation' => 'dedicated', 'tenants' => ['acme' => [], 'globex' => []]],
    ]);

    $aa = [];
    $cw = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => []]),
        'PutScalingPolicy' => new Result(['PolicyARN' => 'arn:aws:autoscaling:ap-southeast-2:111111111111:scalingPolicy:x']),
    ], $aa);
    bindMockCloudWatchClient([
        'DescribeAlarms' => new Result(['MetricAlarms' => [
            ['AlarmName' => 'yolo-testing-my-app-queue-has-messages', 'AlarmArn' => 'arn:aws:cloudwatch:ap-southeast-2:111111111111:alarm:x'],
        ]]),
        'ListTagsForResource' => new Result(['Tags' => []]),
    ], $cw);

    (new QueueScaleToZeroBootstrap())->synchronise(apply: true);

    // Metric-math form: one term per queue, the SUM is what's compared to the
    // threshold. The single-metric keys are mutually exclusive with Metrics on
    // PutMetricAlarm, so none of them may leak through.
    $alarm = collect($cw)->firstWhere('name', 'PutMetricAlarm')['args'];
    expect($alarm)->not->toHaveKeys(['MetricName', 'Namespace', 'Dimensions', 'Statistic', 'Period']);

    $metrics = collect($alarm['Metrics']);
    expect($metrics->pluck('Id')->all())->toBe(['visible_landlord', 'visible_acme', 'visible_globex', 'visible']);
    expect($metrics->firstWhere('Id', 'visible'))->toMatchArray([
        'Expression' => 'SUM([visible_landlord, visible_acme, visible_globex])',
        'ReturnData' => true,
    ]);
    expect($metrics->firstWhere('Id', 'visible_acme')['MetricStat'])->toMatchArray(['Period' => 60, 'Stat' => 'Maximum']);
    expect($metrics->firstWhere('Id', 'visible_acme')['ReturnData'])->toBeFalse();
    expect($alarm)->toMatchArray(['Threshold' => 0, 'ComparisonOperator' => 'GreaterThanThreshold']);
});

it('re-puts the alarm when a tenant is added so it watches the new queue too', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => ['autoscaling' => ['min' => 0]]],
        'multitenancy' => ['queue-isolation' => 'dedicated', 'tenants' => ['acme' => [], 'initech' => []]],
    ]);

    // The live alarm still sums only landlord + acme, from before initech was declared.
    $liveMetrics = collect(['landlord', 'acme'])->map(fn (string $scope): array => [
        'Id' => "visible_$scope",
        'MetricStat' => ['Metric' => [
            'Namespace' => 'AWS/SQS',
            'MetricName' => 'ApproximateNumberOfMessagesVisible',
            'Dimensions' => [['Name' => 'QueueName', 'Value' => "yolo-testing-my-app-$scope"]],
        ]],
    ])->all();

    $aa = [];
    $cw = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => [['PolicyName' => 'yolo-testing-my-app-queue-bootstrap-policy']]]),
        'PutScalingPolicy' => new Result(['PolicyARN' => 'arn:aws:autoscaling:ap-southeast-2:111111111111:scalingPolicy:x']),
    ], $aa);
    bindMockCloudWatchClient([
        'DescribeAlarms' => new Result(['MetricAlarms' => [[
            'AlarmName' => 'yolo-testing-my-app-queue-has-messages',
            'AlarmArn' => 'arn:aws:cloudwatch:ap-southeast-2:111111111111:alarm:x',
            'Metrics' => $liveMetrics,
        ]]]),
        'ListTagsForResource' => new Result(['Tags' => []]),
    ], $cw);

    $bootstrap = new QueueScaleToZeroBootstrap();

    $planned = $bootstrap->synchronise(apply: false);
    expect(collect($planned)->pluck('attribute')->all())->toBe(['queue scale-to-zero alarm queues']);
    expect($planned[0]->to)->toBe('yolo-testing-my-app-acme, yolo-testing-my-app-initech, yolo-testing-my-app-landlord');
    expect(collect($cw)->pluck('name'))->not->toContain('PutMetricAlarm');

    $bootstrap->synchronise(apply: true);

    $alarm = collect($cw)->firstWhere('name', 'PutMetricAlarm')['args'];
    expect(collect($alarm['Metrics'])->pluck('Id')->all())->toBe(['visible_landlord', 'visible_acme', 'visible_initech', 'visible']);
});
