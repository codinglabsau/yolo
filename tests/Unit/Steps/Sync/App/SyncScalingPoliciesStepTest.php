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
