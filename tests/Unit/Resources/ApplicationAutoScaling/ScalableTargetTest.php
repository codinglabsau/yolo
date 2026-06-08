<?php

use Aws\Result;
use Codinglabs\Yolo\Enums\ServerGroup;
use Codinglabs\Yolo\Resources\ApplicationAutoScaling\ScalableTarget;

beforeEach(function (): void {
    writeManifest([
        'account-id' => '111111111111',
        'region' => 'ap-southeast-2',
        'tasks' => ['web' => ['autoscaling' => ['min' => 2, 'max' => 6]]],
    ]);
});

it('builds the ECS service resource id', function (): void {
    expect(ScalableTarget::resourceId())->toBe('service/yolo-testing-my-app/yolo-testing-my-app-web');
});

it('reports the target absent when none is registered', function (): void {
    $captured = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalableTargets' => new Result(['ScalableTargets' => []]),
    ], $captured);

    expect((new ScalableTarget())->exists())->toBeFalse();
    expect((new ScalableTarget())->current())->toBeNull();
});

it('registers the target with the manifest min/max when absent', function (): void {
    $captured = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalableTargets' => new Result(['ScalableTargets' => []]),
        'RegisterScalableTarget' => new Result([]),
    ], $captured);

    $changes = (new ScalableTarget())->synchronise(apply: true);

    expect($changes)->toHaveCount(2);

    $register = collect($captured)->firstWhere('name', 'RegisterScalableTarget');

    expect($register['args'])->toMatchArray([
        'ServiceNamespace' => 'ecs',
        'ResourceId' => 'service/yolo-testing-my-app/yolo-testing-my-app-web',
        'ScalableDimension' => 'ecs:service:DesiredCount',
        'MinCapacity' => 2,
        'MaxCapacity' => 6,
    ]);
});

it('does not register when the live min/max already match', function (): void {
    $captured = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalableTargets' => new Result(['ScalableTargets' => [
            ['MinCapacity' => 2, 'MaxCapacity' => 6],
        ]]),
    ], $captured);

    $changes = (new ScalableTarget())->synchronise(apply: true);

    expect($changes)->toBe([]);
    expect(collect($captured)->pluck('name'))->not->toContain('RegisterScalableTarget');
});

it('reports drift but does not register on a dry-run', function (): void {
    $captured = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalableTargets' => new Result(['ScalableTargets' => [
            ['MinCapacity' => 1, 'MaxCapacity' => 6],
        ]]),
    ], $captured);

    $changes = (new ScalableTarget())->synchronise(apply: false);

    expect($changes)->toHaveCount(1);
    expect($changes[0]->describe())->toContain('MinCapacity');
    expect(collect($captured)->pluck('name'))->not->toContain('RegisterScalableTarget');
});

it('builds the queue service resource id and defaults its floor to zero when the scheduler is extracted', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => [], 'scheduler' => []],
    ]);

    expect(ScalableTarget::resourceId(ServerGroup::QUEUE))->toBe('service/yolo-testing-my-app/yolo-testing-my-app-queue');
    expect((new ScalableTarget(ServerGroup::QUEUE))->min())->toBe(0);
    expect((new ScalableTarget(ServerGroup::QUEUE))->max())->toBe(10);
});

it('floors the queue at one task when it also hosts the scheduler', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => []],
    ]);

    // No dedicated scheduler service → the scheduler rides the queue, so it can't
    // scale to zero (cron would stop) — the floor defaults to 1.
    expect((new ScalableTarget(ServerGroup::QUEUE))->min())->toBe(1);
});

it('registers the queue target with a zero floor (scale to zero)', function (): void {
    writeManifest([
        'account-id' => '111111111111', 'region' => 'ap-southeast-2',
        'tasks' => ['web' => [], 'queue' => ['min' => 0, 'max' => 20], 'scheduler' => []],
    ]);

    $captured = [];
    bindMockApplicationAutoScalingClient([
        'DescribeScalableTargets' => new Result(['ScalableTargets' => []]),
        'RegisterScalableTarget' => new Result([]),
    ], $captured);

    (new ScalableTarget(ServerGroup::QUEUE))->synchronise(apply: true);

    expect(collect($captured)->firstWhere('name', 'RegisterScalableTarget')['args'])->toMatchArray([
        'ResourceId' => 'service/yolo-testing-my-app/yolo-testing-my-app-queue',
        'MinCapacity' => 0,
        'MaxCapacity' => 20,
    ]);
});

it('deregisters the target with the fixed namespace and dimension', function (): void {
    $captured = [];
    bindMockApplicationAutoScalingClient(['DeregisterScalableTarget' => new Result([])], $captured);

    (new ScalableTarget())->deregister();

    $call = collect($captured)->firstWhere('name', 'DeregisterScalableTarget');

    expect($call['args'])->toMatchArray([
        'ServiceNamespace' => 'ecs',
        'ResourceId' => 'service/yolo-testing-my-app/yolo-testing-my-app-web',
        'ScalableDimension' => 'ecs:service:DesiredCount',
    ]);
});
