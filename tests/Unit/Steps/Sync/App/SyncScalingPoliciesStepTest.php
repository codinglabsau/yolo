<?php

use Aws\Result;
use Codinglabs\Yolo\Helpers;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\SyncScalingPoliciesStep;

/**
 * A live CPU policy whose config matches the manifest default, so it reports no
 * drift and the loop neither creates nor syncs it.
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

/**
 * A live concurrency policy matching the default-memory target, so it reports no
 * drift either — leaving the prune as the only action under test.
 *
 * @return array<string, mixed>
 */
function matchingConcurrencyPolicy(string $name): array
{
    return [
        'PolicyName' => $name,
        'TargetTrackingScalingPolicyConfiguration' => [
            'TargetValue' => 23.0,
            'CustomizedMetricSpecification' => ['Metrics' => [
                ['Id' => 'concurrency', 'Expression' => '(requests / 60) * latency', 'ReturnData' => true],
            ]],
            'ScaleOutCooldown' => 60,
            'ScaleInCooldown' => 300,
        ],
    ];
}

/**
 * The live ALB + target group the concurrency policy resolves its metric
 * dimensions from.
 */
function bindResolvableLoadBalancer(): void
{
    $elb = [];
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => [['LoadBalancerName' => 'yolo-testing', 'LoadBalancerArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:loadbalancer/app/yolo-testing/abc123']]]),
        'DescribeTargetGroups' => new Result(['TargetGroups' => [['TargetGroupArn' => 'arn:aws:elasticloadbalancing:ap-southeast-2:111111111111:targetgroup/yolo-testing-my-app/def456']]]),
    ], $elb);
}

function bindUnresolvableLoadBalancer(): void
{
    $elb = [];
    bindRoutedElbV2Client([
        'DescribeLoadBalancers' => new Result(['LoadBalancers' => []]),
        'DescribeTargetGroups' => new Result(['TargetGroups' => []]),
    ], $elb);
}

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => ['cpu-utilization' => 65]]],
    ]);
});

it('composes the CPU and concurrency policies by default', function (): void {
    bindResolvableLoadBalancer();

    expect(SyncScalingPoliciesStep::policies())->toHaveCount(2);
});

it('composes only the CPU policy when the ALB/TG are not resolvable yet', function (): void {
    bindUnresolvableLoadBalancer();

    // Concurrency is deferred to the next sync (it needs the ALB/TG to build its
    // metric dimensions); CPU has no such dependency and is always present.
    expect(SyncScalingPoliciesStep::concurrencyPolicy())->toBeNull();
    expect(SyncScalingPoliciesStep::policies())->toHaveCount(1);
});

it('skips when the ECS service does not exist yet', function (): void {
    $captured = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => []])], $captured);

    expect((new SyncScalingPoliciesStep())([]))->toBe(StepResult::SKIPPED);
});

it('would-create the policies on a dry-run without putting', function (): void {
    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'serviceArn' => 'arn']]])], $ecs);
    bindResolvableLoadBalancer();
    bindMockApplicationAutoScalingClient(['DescribeScalingPolicies' => new Result(['ScalingPolicies' => []])], $aa);

    expect((new SyncScalingPoliciesStep())(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE);
    expect(collect($aa)->pluck('name'))->not->toContain('PutScalingPolicy');
});

it('creates both the CPU and concurrency policies when applying', function (): void {
    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'serviceArn' => 'arn']]])], $ecs);
    bindResolvableLoadBalancer();
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => []]),
        'PutScalingPolicy' => new Result([]),
    ], $aa);

    expect((new SyncScalingPoliciesStep())([]))->toBe(StepResult::CREATED);

    $puts = collect($aa)->where('name', 'PutScalingPolicy');
    expect($puts)->toHaveCount(2);

    // One predefined CPU policy, one customized-metric concurrency policy.
    $cpu = $puts->first(fn ($call): bool => isset($call['args']['TargetTrackingScalingPolicyConfiguration']['PredefinedMetricSpecification']));
    expect($cpu['args']['TargetTrackingScalingPolicyConfiguration']['PredefinedMetricSpecification']['PredefinedMetricType'])
        ->toBe('ECSServiceAverageCPUUtilization');

    $concurrency = $puts->first(fn ($call): bool => isset($call['args']['TargetTrackingScalingPolicyConfiguration']['CustomizedMetricSpecification']));
    $returning = collect($concurrency['args']['TargetTrackingScalingPolicyConfiguration']['CustomizedMetricSpecification']['Metrics'])
        ->firstWhere('Id', 'concurrency');
    expect($returning['Expression'])->toBe('(requests / 60) * latency');
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

it('prunes a leftover request-count policy the manifest no longer wants', function (): void {
    $cpu = Helpers::keyedResourceName('cpu-scaling-policy');
    $concurrency = Helpers::keyedResourceName('concurrency-scaling-policy');
    $requestCount = Helpers::keyedResourceName('request-count-scaling-policy');

    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'serviceArn' => 'arn']]])], $ecs);
    bindResolvableLoadBalancer();
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => [matchingCpuPolicy($cpu), matchingConcurrencyPolicy($concurrency), ['PolicyName' => $requestCount]]]),
        'DeleteScalingPolicy' => new Result([]),
    ], $aa);

    expect((new SyncScalingPoliciesStep())([]))->toBe(StepResult::DELETED);

    $delete = collect($aa)->firstWhere('name', 'DeleteScalingPolicy');
    expect($delete)->not->toBeNull();
    expect($delete['args']['PolicyName'])->toBe($requestCount);
});

it('would-prune on a dry-run without deleting', function (): void {
    $cpu = Helpers::keyedResourceName('cpu-scaling-policy');
    $concurrency = Helpers::keyedResourceName('concurrency-scaling-policy');
    $requestCount = Helpers::keyedResourceName('request-count-scaling-policy');

    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'serviceArn' => 'arn']]])], $ecs);
    bindResolvableLoadBalancer();
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => [matchingCpuPolicy($cpu), matchingConcurrencyPolicy($concurrency), ['PolicyName' => $requestCount]]]),
        'DeleteScalingPolicy' => new Result([]),
    ], $aa);

    expect((new SyncScalingPoliciesStep())(['dry-run' => true]))->toBe(StepResult::WOULD_DELETE);
    expect(collect($aa)->pluck('name'))->not->toContain('DeleteScalingPolicy');
});

it('does not prune the concurrency policy when it is only deferred (ALB/TG unresolved)', function (): void {
    $cpu = Helpers::keyedResourceName('cpu-scaling-policy');
    $concurrency = Helpers::keyedResourceName('concurrency-scaling-policy');

    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'serviceArn' => 'arn']]])], $ecs);
    // ALB / target group don't resolve → concurrency is deferred, not removed:
    // desiredPolicyNames() still wants it, so a live one must NOT be pruned.
    bindUnresolvableLoadBalancer();
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => [matchingCpuPolicy($cpu), ['PolicyName' => $concurrency]]]),
        'DeleteScalingPolicy' => new Result([]),
    ], $aa);

    (new SyncScalingPoliciesStep())([]);

    expect(collect($aa)->pluck('name'))->not->toContain('DeleteScalingPolicy');
});
