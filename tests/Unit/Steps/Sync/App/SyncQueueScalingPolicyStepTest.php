<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\StepResult;
use Codinglabs\Yolo\Steps\Sync\App\SyncQueueScalingPolicyStep;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => true],
    ]);
});

it('skips and provisions nothing when the queue is not autoscaling', function (): void {
    // tasks.queue.autoscaling: false → a fixed single task; the backlog policy is
    // torn down by the scalable target's deregistration, so this step just skips.
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => true, 'queue' => ['autoscaling' => false]],
    ]);

    $aa = [];
    bindMockApplicationAutoScalingClient(['DescribeScalingPolicies' => new Result(['ScalingPolicies' => []])], $aa);

    expect((new SyncQueueScalingPolicyStep())([]))->toBe(StepResult::SKIPPED);
    expect(collect($aa)->pluck('name'))->not->toContain('PutScalingPolicy');
});

it('records the pending backlog policy on a greenfield plan pass instead of skipping', function (): void {
    // Nothing exists yet — the step must survive the plan pass with the policy
    // pending (two-pass contract), not prune itself with a bare SKIPPED; the
    // queue service and scalable target it attaches to are created earlier in
    // the same apply pass. No ECS client is bound: no gating on the live service.
    $aa = [];
    bindMockApplicationAutoScalingClient(['DescribeScalingPolicies' => new Result(['ScalingPolicies' => []])], $aa);

    $step = new SyncQueueScalingPolicyStep();

    expect($step(['dry-run' => true]))->toBe(StepResult::WOULD_CREATE)
        ->and($step->changes())->not->toBeEmpty()
        ->and(collect($aa)->pluck('name'))->not->toContain('PutScalingPolicy');
});

it('creates the backlog policy when the queue autoscales and its service exists', function (): void {
    $ecs = [];
    $aa = [];
    bindRoutedEcsClient(['DescribeServices' => new Result(['services' => [['status' => 'ACTIVE', 'serviceArn' => 'arn']]])], $ecs);
    bindMockApplicationAutoScalingClient([
        'DescribeScalingPolicies' => new Result(['ScalingPolicies' => []]),
        'PutScalingPolicy' => new Result(['PolicyARN' => 'arn:aws:autoscaling:ap-southeast-2:111111111111:scalingPolicy:x']),
    ], $aa);

    expect((new SyncQueueScalingPolicyStep())([]))->toBe(StepResult::CREATED);
    expect(collect($aa)->pluck('name'))->toContain('PutScalingPolicy');
});
