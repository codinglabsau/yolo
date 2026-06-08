<?php

use Aws\Result;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\SyncScalingPoliciesStep;

/**
 * A live CPU policy whose config matches the manifest default, so it reports no
 * drift and the loop neither creates nor syncs it — leaving the prune as the only
 * action under test.
 *
 * @return array<string, mixed>
 */
function matchingCpuPolicy(string $name): array
{
    return [
        'PolicyName' => $name,
        'TargetTrackingScalingPolicyConfiguration' => [
            'TargetValue' => 65.0,
            'PredefinedMetricSpecification' => ['PredefinedMetricType' => 'ECSServiceAverageCPUUtilization'],
            'ScaleOutCooldown' => 60,
            'ScaleInCooldown' => 300,
        ],
    ];
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => ['cpu-utilization' => 65]]],
    ]);
});

it('composes only the CPU policy when no request-count target is set', function (): void {
    $policies = SyncScalingPoliciesStep::policies();

    expect($policies)->toHaveCount(1);
});

it('skips when the ECS service does not exist yet', function (): void {
    $captured = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => []])], $captured);

    expect((new SyncScalingPoliciesStep())([]))->toBe(StepResult::SKIPPED);
});

it('would-create the CPU policy on a dry-run without putting it', function (): void {
    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'serviceArn' => 'arn']]])], $ecs);
    bindMockApplicationAutoScalingClient(['DescribeScalingPolicies' => new Result(['ScalingPolicies' => []])], $aa);

    expect((new SyncScalingPoliciesStep())(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE);
    expect(collect($aa)->pluck('name'))->not->toContain('PutScalingPolicy');
});

it('creates the CPU policy when applying', function (): void {
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

it('builds the {alb-suffix}/{tg-suffix} ResourceLabel from the live ALB and target group', function (): void {
    $elb = [];
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => [['LoadBalancerName' => 'yolo-testing', 'LoadBalancerArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:loadbalancer/app/yolo-testing/abc123']]]),
        'DescribeTargetGroups' => new Result(['TargetGroups' => [['TargetGroupArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:targetgroup/yolo-testing-my-app/def456']]]),
    ], $elb);

    expect(SyncScalingPoliciesStep::resourceLabel())->toBe('app/yolo-testing/abc123/targetgroup/yolo-testing-my-app/def456');
});

it('composes both the CPU and request-count policies when request-count is set and the ALB/TG resolve', function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => ['cpu-utilization' => 65, 'request-count-per-target' => 1000]]],
    ]);

    $elb = [];
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => [['LoadBalancerName' => 'yolo-testing', 'LoadBalancerArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:loadbalancer/app/yolo-testing/abc123']]]),
        'DescribeTargetGroups' => new Result(['TargetGroups' => [['TargetGroupArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:targetgroup/yolo-testing-my-app/def456']]]),
    ], $elb);

    expect(SyncScalingPoliciesStep::policies())->toHaveCount(2);
});

it('defers the request-count policy when the ALB/TG are not resolvable yet', function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
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

it('puts the request-count policy with its ResourceLabel when applying', function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
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

    $metrics = $puts->map(fn ($call): mixed => $call['args']['TargetTrackingScalingPolicyConfiguration']['PredefinedMetricSpecification']['PredefinedMetricType']);
    expect($metrics)->toContain('ECSServiceAverageCPUUtilization', 'ALBRequestCountPerTarget');

    $requestCount = $puts->first(fn ($call): bool => $call['args']['TargetTrackingScalingPolicyConfiguration']['PredefinedMetricSpecification']['PredefinedMetricType'] === 'ALBRequestCountPerTarget');
    expect($requestCount['args']['TargetTrackingScalingPolicyConfiguration']['PredefinedMetricSpecification']['ResourceLabel'])
        ->toBe('app/yolo-testing/abc123/targetgroup/yolo-testing-my-app/def456');
});

it('skips entirely when autoscaling is removed from the manifest', function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => []],
    ]);

    $aa = [];
    bindMockApplicationAutoScalingClient([], $aa);

    expect((new SyncScalingPoliciesStep())([]))->toBe(StepResult::SKIPPED);
    expect($aa)->toBeEmpty();
});

it('prunes a live policy the manifest no longer wants (request-count removed)', function (): void {
    // beforeEach manifest has the autoscaling block but no request-count → CPU is
    // the only desired policy, so a live request-count policy is an orphan.
    $cpu = Helpers::keyedResourceName('cpu-scaling-policy');
    $requestCount = Helpers::keyedResourceName('request-count-scaling-policy');

    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'serviceArn' => 'arn']]])], $ecs);
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => [matchingCpuPolicy($cpu), ['PolicyName' => $requestCount]]]),
        'DeleteScalingPolicy' => new Result([]),
    ], $aa);

    expect((new SyncScalingPoliciesStep())([]))->toBe(StepResult::DELETED);

    $delete = collect($aa)->firstWhere('name', 'DeleteScalingPolicy');
    expect($delete)->not->toBeNull();
    expect($delete['args']['PolicyName'])->toBe($requestCount);
});

it('would-prune on a dry-run without deleting', function (): void {
    $cpu = Helpers::keyedResourceName('cpu-scaling-policy');
    $requestCount = Helpers::keyedResourceName('request-count-scaling-policy');

    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'serviceArn' => 'arn']]])], $ecs);
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => [matchingCpuPolicy($cpu), ['PolicyName' => $requestCount]]]),
        'DeleteScalingPolicy' => new Result([]),
    ], $aa);

    expect((new SyncScalingPoliciesStep())(['dry-run' => true]))->toBe(StepResult::WOULD_DELETE);
    expect(collect($aa)->pluck('name'))->not->toContain('DeleteScalingPolicy');
});

it('does not prune the request-count policy when it is only deferred (ALB/TG unresolved)', function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => ['cpu-utilization' => 65, 'request-count-per-target' => 1000]]],
    ]);

    $cpu = Helpers::keyedResourceName('cpu-scaling-policy');
    $requestCount = Helpers::keyedResourceName('request-count-scaling-policy');

    $ecs = [];
    $aa = [];
    $elb = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'serviceArn' => 'arn']]])], $ecs);
    // ALB / target group don't resolve → request-count is deferred, not removed:
    // desiredPolicyNames() still wants it, so it must NOT be pruned.
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => []]),
        'DescribeTargetGroups' => new Result(['TargetGroups' => []]),
    ], $elb);
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => [matchingCpuPolicy($cpu), ['PolicyName' => $requestCount]]]),
        'DeleteScalingPolicy' => new Result([]),
    ], $aa);

    (new SyncScalingPoliciesStep())([]);

    expect(collect($aa)->pluck('name'))->not->toContain('DeleteScalingPolicy');
});
