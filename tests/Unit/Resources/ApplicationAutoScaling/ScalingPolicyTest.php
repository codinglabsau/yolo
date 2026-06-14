<?php

use Aws\Result;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\ScalingPolicy;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => ['cpu-utilization' => 65]]],
    ]);
});

it('builds a predefined CPU target-tracking configuration', function (): void {
    expect((new ScalingPolicy('p', 'ECSServiceAverageCPUUtilization', 65.0))->configuration())->toBe([
        'TargetValue' => 65.0,
        'PredefinedMetricSpecification' => ['PredefinedMetricType' => 'ECSServiceAverageCPUUtilization'],
        'ScaleOutCooldown' => 60,
        'ScaleInCooldown' => 300,
    ]);
});

it('reports every comparable field as drift when the policy is absent', function (): void {
    // target, metric, scale-out and scale-in cooldowns all drift against a null live policy.
    expect((new ScalingPolicy('p', 'ECSServiceAverageCPUUtilization', 65.0))->drift(null))->toHaveCount(4);
});

it('reports no drift when the live policy already matches', function (): void {
    $live = ['TargetTrackingScalingPolicyConfiguration' => [
        'TargetValue' => 65.0,
        'PredefinedMetricSpecification' => ['PredefinedMetricType' => 'ECSServiceAverageCPUUtilization'],
        'ScaleOutCooldown' => 60,
        'ScaleInCooldown' => 300,
    ]];

    expect((new ScalingPolicy('p', 'ECSServiceAverageCPUUtilization', 65.0))->drift($live))->toBe([]);
});

it('puts the policy when it drifts and captures the configuration', function (): void {
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

it('does not put when the live policy already matches', function (): void {
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
