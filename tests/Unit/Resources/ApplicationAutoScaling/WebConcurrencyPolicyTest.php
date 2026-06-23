<?php

use Aws\Result;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\WebConcurrencyPolicy;

function concurrencyPolicy(string $name = 'yolo-testing-my-app-concurrency-scaling-policy'): WebConcurrencyPolicy
{
    return new WebConcurrencyPolicy(
        policyName: $name,
        loadBalancerDimension: 'app/yolo-testing/abc123',
        targetGroupDimension: 'targetgroup/yolo-testing-my-app/def456',
    );
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => ['min' => 1, 'max' => 6]]],
    ]);
});

it('derives the concurrency target from the pinned worker pool of the default 0.5 vCPU task', function (): void {
    // Default web task is 0.5 vCPU → 8 workers; 8 * 0.7 = 5.6, floored to 5.
    expect(concurrencyPolicy()->targetValue())->toBe(5.0);
});

it('scales the concurrency target with the task vCPU allocation', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['cpu' => 1024, 'memory' => 2048, 'autoscaling' => ['min' => 1, 'max' => 6]]],
    ]);

    // 1 vCPU → 16 workers; 16 * 0.7 = 11.2, floored to 11.
    expect(concurrencyPolicy()->targetValue())->toBe(11.0);
});

it('never targets below one in-flight request on a tiny task', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['memory' => 32, 'autoscaling' => ['min' => 1, 'max' => 6]]],
    ]);

    // 32 MB caps the pool at 0 whole 64 MB workers → clamped to 1; 1 * 0.7 = 0.7,
    // floored to 0 → clamped up to 1.
    expect(concurrencyPolicy()->targetValue())->toBe(1.0);
});

it('tracks in-flight concurrency per task with metric math from request rate and latency', function (): void {
    $config = concurrencyPolicy()->configuration();

    expect($config['TargetValue'])->toBe(5.0);

    $metrics = collect($config['CustomizedMetricSpecification']['Metrics']);

    // RequestCountPerTarget (per-target rate, Sum) on this app's target group, the
    // average response time (latency) on the same group, and the Little's-Law
    // expression that turns the two into per-task in-flight concurrency.
    expect($metrics->firstWhere('Id', 'requests')['MetricStat'])->toMatchArray([
        'Metric' => [
            'Namespace' => 'AWS/ApplicationELB',
            'MetricName' => 'RequestCountPerTarget',
            'Dimensions' => [['Name' => 'TargetGroup', 'Value' => 'targetgroup/yolo-testing-my-app/def456']],
        ],
        'Stat' => 'Sum',
    ]);
    expect($metrics->firstWhere('Id', 'latency')['MetricStat'])->toMatchArray([
        'Metric' => [
            'Namespace' => 'AWS/ApplicationELB',
            'MetricName' => 'TargetResponseTime',
            'Dimensions' => [
                ['Name' => 'TargetGroup', 'Value' => 'targetgroup/yolo-testing-my-app/def456'],
                ['Name' => 'LoadBalancer', 'Value' => 'app/yolo-testing/abc123'],
            ],
        ],
        'Stat' => 'Average',
    ]);
    expect($metrics->firstWhere('Id', 'concurrency'))->toMatchArray([
        'Expression' => '(requests / 60) * latency',
        'ReturnData' => true,
    ]);

    // Only the expression returns data; the two source metrics feed it.
    expect($metrics->firstWhere('Id', 'requests')['ReturnData'])->toBeFalse();
    expect($metrics->firstWhere('Id', 'latency')['ReturnData'])->toBeFalse();
});

it('upserts the target-tracking policy onto the web scalable target when absent', function (): void {
    $captured = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => []]),
        'PutScalingPolicy' => new Result(['PolicyARN' => 'arn:aws:autoscaling:...:policy/concurrency']),
    ], $captured);

    $changes = concurrencyPolicy()->synchronise(apply: true);

    expect($changes)->not->toBe([]);

    $put = collect($captured)->firstWhere('name', 'PutScalingPolicy');
    expect($put['args'])->toMatchArray([
        'PolicyType' => 'TargetTrackingScaling',
        'ResourceId' => 'service/yolo-testing-my-app/yolo-testing-my-app-web',
    ]);
});

it('reports drift without writing on a dry-run', function (): void {
    $captured = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => []]),
    ], $captured);

    $changes = concurrencyPolicy()->synchronise(apply: false);

    expect($changes)->not->toBe([]);
    expect(collect($captured)->pluck('name'))->not->toContain('PutScalingPolicy');
});

it('reports no drift when the live policy already matches', function (): void {
    $live = ['TargetTrackingScalingPolicyConfiguration' => [
        'TargetValue' => 5.0,
        'CustomizedMetricSpecification' => ['Metrics' => [
            ['Id' => 'concurrency', 'Expression' => '(requests / 60) * latency', 'ReturnData' => true],
        ]],
        'ScaleOutCooldown' => 60,
        'ScaleInCooldown' => 300,
    ]];

    expect(concurrencyPolicy()->drift($live))->toBe([]);
});

it('does not report drift when AWS reformats the expression whitespace on read-back', function (): void {
    $live = ['TargetTrackingScalingPolicyConfiguration' => [
        'TargetValue' => 5.0,
        'CustomizedMetricSpecification' => ['Metrics' => [
            // Same formula, AWS-normalised spacing — must not look like drift.
            ['Id' => 'concurrency', 'Expression' => '(requests/60)*latency', 'ReturnData' => true],
        ]],
        'ScaleOutCooldown' => 60,
        'ScaleInCooldown' => 300,
    ]];

    expect(concurrencyPolicy()->drift($live))->toBe([]);
});
