<?php

use Aws\Result;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\QueueBacklogPolicy;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => true],
    ]);
});

it('tracks backlog-per-task with metric math dividing visible messages by running tasks', function (): void {
    $config = (new QueueBacklogPolicy())->configuration();

    expect($config['TargetValue'])->toBe(100.0);

    $metrics = collect($config['CustomizedMetricSpecification']['Metrics']);

    // The visible-messages metric on this app's queue, the running-task count, and
    // the math expression that divides them — only the expression returns data.
    expect($metrics->firstWhere('Id', 'visible')['MetricStat']['Metric'])->toMatchArray([
        'Namespace' => 'AWS/SQS',
        'MetricName' => 'ApproximateNumberOfMessagesVisible',
    ]);
    expect($metrics->firstWhere('Id', 'running')['MetricStat']['Metric']['MetricName'])->toBe('RunningTaskCount');
    expect($metrics->firstWhere('Id', 'backlog_per_task'))->toMatchArray([
        'Expression' => 'visible / running',
        'ReturnData' => true,
    ]);
});

it('reads the backlog-per-task target from the manifest', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => ['autoscaling' => ['backlog-per-task' => 40]]],
    ]);

    expect((new QueueBacklogPolicy())->configuration()['TargetValue'])->toBe(40.0);
});

it('upserts the target-tracking policy onto the queue scalable target when absent', function (): void {
    $captured = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => []]),
        'PutScalingPolicy' => new Result(['PolicyARN' => 'arn:aws:autoscaling:...:policy/queue']),
    ], $captured);

    $changes = (new QueueBacklogPolicy())->synchronise(apply: true);

    expect($changes)->not->toBe([]);

    $put = collect($captured)->firstWhere('name', 'PutScalingPolicy');
    expect($put['args'])->toMatchArray([
        'PolicyType' => 'TargetTrackingScaling',
        'ResourceId' => 'service/yolo-testing-my-app/yolo-testing-my-app-queue',
    ]);
});

it('reports drift without writing on a dry-run', function (): void {
    $captured = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => []]),
    ], $captured);

    $changes = (new QueueBacklogPolicy())->synchronise(apply: false);

    expect($changes)->not->toBe([]);
    expect(collect($captured)->pluck('name'))->not->toContain('PutScalingPolicy');
});
