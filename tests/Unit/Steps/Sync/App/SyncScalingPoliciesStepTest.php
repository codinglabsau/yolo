<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\SyncScalingPoliciesStep;

beforeEach(function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => ['autoscaling' => ['cpu-utilization' => 65]]],
    ]);
});

it('composes only the CPU policy when no request-count target is set', function () {
    $policies = SyncScalingPoliciesStep::policies();

    expect($policies)->toHaveCount(1);
});

it('skips when the ECS service does not exist yet', function () {
    $captured = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => []])], $captured);

    expect((new SyncScalingPoliciesStep())([]))->toBe(StepResult::SKIPPED);
});

it('would-create the CPU policy on a dry-run without putting it', function () {
    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'serviceArn' => 'arn']]])], $ecs);
    bindMockApplicationAutoScalingClient(['DescribeScalingPolicies' => new Result(['ScalingPolicies' => []])], $aa);

    expect((new SyncScalingPoliciesStep())(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE);
    expect(collect($aa)->pluck('name'))->not->toContain('PutScalingPolicy');
});

it('creates the CPU policy when applying', function () {
    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'serviceArn' => 'arn']]])], $ecs);
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => []]),
        'PutScalingPolicy' => new Result([]),
    ], $aa);

    expect((new SyncScalingPoliciesStep())([]))->toBe(StepResult::CREATED);
    expect(collect($aa)->pluck('name'))->toContain('PutScalingPolicy');
});

it('builds the {alb-suffix}/{tg-suffix} ResourceLabel from the live ALB and target group', function () {
    $elb = [];
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => [['LoadBalancerName' => 'yolo-testing', 'LoadBalancerArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:loadbalancer/app/yolo-testing/abc123']]]),
        'DescribeTargetGroups' => new Result(['TargetGroups' => [['TargetGroupArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:targetgroup/yolo-testing-my-app/def456']]]),
    ], $elb);

    expect(SyncScalingPoliciesStep::resourceLabel())->toBe('app/yolo-testing/abc123/targetgroup/yolo-testing-my-app/def456');
});

it('composes both the CPU and request-count policies when request-count is set and the ALB/TG resolve', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => ['autoscaling' => ['cpu-utilization' => 65, 'request-count-per-target' => 1000]]],
    ]);

    $elb = [];
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => [['LoadBalancerName' => 'yolo-testing', 'LoadBalancerArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:loadbalancer/app/yolo-testing/abc123']]]),
        'DescribeTargetGroups' => new Result(['TargetGroups' => [['TargetGroupArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:targetgroup/yolo-testing-my-app/def456']]]),
    ], $elb);

    expect(SyncScalingPoliciesStep::policies())->toHaveCount(2);
});

it('defers the request-count policy when the ALB/TG are not resolvable yet', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => ['autoscaling' => ['cpu-utilization' => 65, 'request-count-per-target' => 1000]]],
    ]);

    $elb = [];
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => []]),
        'DescribeTargetGroups' => new Result(['TargetGroups' => []]),
    ], $elb);

    expect(SyncScalingPoliciesStep::resourceLabel())->toBeNull();
    expect(SyncScalingPoliciesStep::policies())->toHaveCount(1); // CPU only — request-count deferred to next sync
});

it('puts the request-count policy with its ResourceLabel when applying', function () {
    writeManifest([
        'aws' => ['account-id' => '111111111111', 'region' => 'ap-southeast-2'],
        'tasks' => ['web' => ['autoscaling' => ['cpu-utilization' => 65, 'request-count-per-target' => 1000]]],
    ]);

    $ecs = [];
    $aa = [];
    $elb = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'serviceArn' => 'arn']]])], $ecs);
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => [['LoadBalancerName' => 'yolo-testing', 'LoadBalancerArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:loadbalancer/app/yolo-testing/abc123']]]),
        'DescribeTargetGroups' => new Result(['TargetGroups' => [['TargetGroupArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:targetgroup/yolo-testing-my-app/def456']]]),
    ], $elb);
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => []]),
        'PutScalingPolicy' => new Result([]),
    ], $aa);

    expect((new SyncScalingPoliciesStep())([]))->toBe(StepResult::CREATED);

    $puts = collect($aa)->where('name', 'PutScalingPolicy');
    expect($puts)->toHaveCount(2);

    $metrics = $puts->map(fn ($call) => $call['args']['TargetTrackingScalingPolicyConfiguration']['PredefinedMetricSpecification']['PredefinedMetricType']);
    expect($metrics)->toContain('ECSServiceAverageCPUUtilization', 'ALBRequestCountPerTarget');

    $requestCount = $puts->first(fn ($call) => $call['args']['TargetTrackingScalingPolicyConfiguration']['PredefinedMetricSpecification']['PredefinedMetricType'] === 'ALBRequestCountPerTarget');
    expect($requestCount['args']['TargetTrackingScalingPolicyConfiguration']['PredefinedMetricSpecification']['ResourceLabel'])
        ->toBe('app/yolo-testing/abc123/targetgroup/yolo-testing-my-app/def456');
});
