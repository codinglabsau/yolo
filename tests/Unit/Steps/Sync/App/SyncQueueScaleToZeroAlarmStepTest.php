<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\SyncQueueScaleToZeroAlarmStep;

it('skips and provisions nothing when the queue is not autoscaling', function (): void {
    // tasks.queue.autoscaling: false → a fixed single task that never idles to zero,
    // so there is no 0→1 deadlock to bootstrap.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => ['autoscaling' => false]],
    ]);

    $aa = [];
    $cw = [];
    bindMockApplicationAutoScalingClient(['DescribeScalingPolicies' => new Result(['ScalingPolicies' => []])], $aa);
    bindMockCloudWatchClient(['DescribeAlarms' => new Result(['MetricAlarms' => []])], $cw);

    expect((new SyncQueueScaleToZeroAlarmStep())([]))->toBe(StepResult::SKIPPED);
    expect(collect($aa)->pluck('name'))->not->toContain('PutScalingPolicy');
    expect(collect($cw)->pluck('name'))->not->toContain('PutMetricAlarm');
});

it('skips a queue with a standing floor (not scale-to-zero)', function (): void {
    // queue: true → autoscaling on with the default floor of 1, so it never sits at
    // zero and needs no bootstrap.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => true],
    ]);

    expect((new SyncQueueScaleToZeroAlarmStep())([]))->toBe(StepResult::SKIPPED);
});

it('provisions the 0→1 bootstrap for a scale-to-zero queue whose service exists', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => ['autoscaling' => ['min' => 0]]],
    ]);

    $alarmArn = 'arn:aws:cloudwatch:ap-southeast-2:111111111111:alarm:yolo-testing-my-app-queue-has-messages';
    $ecs = [];
    $aa = [];
    $cw = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'serviceArn' => 'arn']]])], $ecs);
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => []]),
        'PutScalingPolicy' => new Result(['PolicyARN' => 'arn:aws:autoscaling:ap-southeast-2:111111111111:scalingPolicy:x']),
    ], $aa);
    bindMockCloudWatchClient([
        'DescribeAlarms' => new Result(['MetricAlarms' => [
            ['AlarmName' => 'yolo-testing-my-app-queue-has-messages', 'AlarmArn' => $alarmArn],
        ]]),
        'ListTagsForResource' => new Result(['Tags' => []]),
    ], $cw);

    expect((new SyncQueueScaleToZeroAlarmStep())([]))->toBe(StepResult::CREATED);
    expect(collect($aa)->pluck('name'))->toContain('PutScalingPolicy');
});

it('reconciles the alarm queue set under dedicated isolation without phantom drift', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => ['autoscaling' => ['min' => 0]]],
        'multitenancy' => ['queue-isolation' => 'dedicated', 'tenants' => ['acme' => []]],
    ]);

    $alarm = fn (array $scopes): array => [
        'AlarmName' => 'yolo-testing-my-app-queue-has-messages',
        'AlarmArn' => 'arn:aws:cloudwatch:ap-southeast-2:111111111111:alarm:x',
        'Metrics' => array_map(fn (string $scope): array => [
            'Id' => "visible_$scope",
            'MetricStat' => ['Metric' => ['Dimensions' => [['Name' => 'QueueName', 'Value' => "yolo-testing-my-app-$scope"]]]],
        ], $scopes),
    ];

    $bind = fn (array $scopes): Closure => function (array &$captured) use ($scopes, $alarm): void {
        $aa = [];
        bindMockApplicationAutoScalingClient([
            'DescribeScalingPolicies' => new Result(['ScalingPolicies' => [['PolicyName' => 'yolo-testing-my-app-queue-bootstrap-policy']]]),
            'PutScalingPolicy' => new Result(['PolicyARN' => 'arn:aws:autoscaling:ap-southeast-2:111111111111:scalingPolicy:x']),
        ], $aa);
        bindMockCloudWatchClient([
            'DescribeAlarms' => new Result(['MetricAlarms' => [$alarm($scopes)]]),
            'ListTagsForResource' => new Result(['Tags' => []]),
        ], $captured);
    };

    assertSyncStepReconciles(
        fn (): SyncQueueScaleToZeroAlarmStep => new SyncQueueScaleToZeroAlarmStep(),
        $bind(['landlord', 'acme']),
        // The live alarm predates the tenant: it only watches the landlord queue.
        $bind(['landlord']),
        'PutMetricAlarm',
    );
});
