<?php

use Aws\Result;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\ScalingPolicy;

beforeEach(function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => ['autoscaling' => ['cpu-utilization' => 65]]],
    ]);
});

it('builds a predefined CPU target-tracking configuration', function () {
    expect((new ScalingPolicy('p', 'ECSServiceAverageCPUUtilization', 65.0))->configuration())->toBe([
        'TargetValue' => 65.0,
        'PredefinedMetricSpecification' => ['PredefinedMetricType' => 'ECSServiceAverageCPUUtilization'],
        'ScaleOutCooldown' => 60,
        'ScaleInCooldown' => 300,
    ]);
});

it('includes the resource label only for request-count policies', function () {
    $config = (new ScalingPolicy('p', 'ALBRequestCountPerTarget', 1000.0, 'app/x/1/targetgroup/y/2'))->configuration();

    expect($config['PredefinedMetricSpecification'])->toBe([
        'PredefinedMetricType' => 'ALBRequestCountPerTarget',
        'ResourceLabel' => 'app/x/1/targetgroup/y/2',
    ]);
});

it('reports every comparable field as drift when the policy is absent', function () {
    // target, metric, scale-out and scale-in cooldowns drift; the resource label
    // is null on both sides for a CPU policy, so it is not a change.
    expect((new ScalingPolicy('p', 'ECSServiceAverageCPUUtilization', 65.0))->drift(null))->toHaveCount(4);
});

it('reports no drift when the live policy already matches', function () {
    $live = ['TargetTrackingScalingPolicyConfiguration' => [
        'TargetValue' => 65.0,
        'PredefinedMetricSpecification' => ['PredefinedMetricType' => 'ECSServiceAverageCPUUtilization'],
        'ScaleOutCooldown' => 60,
        'ScaleInCooldown' => 300,
    ]];

    expect((new ScalingPolicy('p', 'ECSServiceAverageCPUUtilization', 65.0))->drift($live))->toBe([]);
});

it('puts the policy when it drifts and captures the configuration', function () {
    $captured = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => []]),
        'PutScalingPolicy' => new Result([]),
    ], $captured);

    $changes = (new ScalingPolicy('yolo-testing-my-app-cpu-scaling-policy', 'ECSServiceAverageCPUUtilization', 65.0))
        ->synchronise(apply: true);

    expect($changes)->not->toBe([]);

    $put = collect($captured)->firstWhere('name', 'PutScalingPolicy');

    expect($put['args'])->toMatchArray([
        'PolicyName' => 'yolo-testing-my-app-cpu-scaling-policy',
        'ServiceNamespace' => 'ecs',
        'ResourceId' => 'service/yolo-testing-my-app/yolo-testing-my-app-web',
        'ScalableDimension' => 'ecs:service:DesiredCount',
        'PolicyType' => 'TargetTrackingScaling',
    ]);
    expect($put['args']['TargetTrackingScalingPolicyConfiguration']['TargetValue'])->toBe(65.0);
});

it('does not put when the live policy already matches', function () {
    $captured = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => [
            [
                'PolicyName' => 'p',
                'TargetTrackingScalingPolicyConfiguration' => [
                    'TargetValue' => 65.0,
                    'PredefinedMetricSpecification' => ['PredefinedMetricType' => 'ECSServiceAverageCPUUtilization'],
                    'ScaleOutCooldown' => 60,
                    'ScaleInCooldown' => 300,
                ],
            ],
        ]]),
    ], $captured);

    $changes = (new ScalingPolicy('p', 'ECSServiceAverageCPUUtilization', 65.0))->synchronise(apply: true);

    expect($changes)->toBe([]);
    expect(collect($captured)->pluck('name'))->not->toContain('PutScalingPolicy');
});
